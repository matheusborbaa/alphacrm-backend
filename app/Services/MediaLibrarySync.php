<?php

namespace App\Services;

use App\Models\Empreendimento;
use App\Models\Lead;
use App\Models\MediaFile;
use App\Models\MediaFolder;
use Illuminate\Support\Facades\Storage;

/**
 * Sincronização entre Empreendimento e Biblioteca de Mídia.
 *
 * Quando um empreendimento é criado/renomeado/excluído, mantemos uma
 * pasta-espelho em /EMPREENDIMENTOS/{nome do empreendimento} pra que
 * gestores subam materiais (book, fotos, plantas, contratos modelo) e
 * só corretores que atendem aquele empreendimento enxerguem.
 *
 * Idempotente: pode ser chamado múltiplas vezes sem duplicar.
 *
 * Observer: registrado em AppServiceProvider via EmpreendimentoObserver.
 */
class MediaLibrarySync
{
    public const ROOT_FOLDER_NAME       = 'EMPREENDIMENTOS';
    public const ROOT_LEADS_FOLDER_NAME = 'LEADS';

    /**
     * Garante que existe a pasta raiz "EMPREENDIMENTOS" e retorna ela.
     * Marcada como system pra UI esconder "Excluir".
     */
    public function ensureRootFolder(): MediaFolder
    {
        return MediaFolder::firstOrCreate(
            [
                'parent_id'         => null,
                'empreendimento_id' => null,
                'name'              => self::ROOT_FOLDER_NAME,
            ],
            [
                'description' => 'Materiais organizados por empreendimento. Cada subpasta é gerenciada automaticamente — apenas corretores com acesso ao empreendimento enxergam o conteúdo.',
                'is_system'   => true,
                'created_by'  => null,
            ]
        );
    }

    /**
     * Garante que existe a subpasta deste empreendimento dentro da raiz
     * "EMPREENDIMENTOS". Se já existe (mesmo empreendimento_id), apenas
     * atualiza o nome caso o empreendimento tenha sido renomeado.
     *
     * Retorna a pasta resultante.
     */
    public function ensureFolderForEmpreendimento(Empreendimento $emp): MediaFolder
    {
        $root = $this->ensureRootFolder();

        // Procuramos por empreendimento_id pra sermos resilientes a renomeações
        // (não dependemos do `name` bater).
        $folder = MediaFolder::where('empreendimento_id', $emp->id)->first();

        if (!$folder) {
            $folder = MediaFolder::create([
                'parent_id'         => $root->id,
                'empreendimento_id' => $emp->id,
                'name'              => $emp->name,
                'description'       => 'Pasta automática do empreendimento ' . $emp->name . '. Visível apenas pra corretores com acesso a este empreendimento.',
                'is_system'         => true,
                'created_by'        => null,
            ]);
            return $folder;
        }

        // Já existe — sincroniza nome se mudou (mantém arquivos/subpastas).
        $changes = [];
        if ($folder->name !== $emp->name) {
            $changes['name'] = $emp->name;
        }
        // Garante que continua dentro da raiz EMPREENDIMENTOS (alguém pode
        // ter movido manualmente via SQL, defesa).
        if ((int) $folder->parent_id !== (int) $root->id) {
            $changes['parent_id'] = $root->id;
        }
        if (!$folder->is_system) {
            $changes['is_system'] = true;
        }
        if ($changes) {
            $folder->update($changes);
        }

        return $folder;
    }

    /**
     * Quando um empreendimento é deletado, removemos junto a pasta-espelho
     * dele na biblioteca + tudo que tem dentro (subpastas, arquivos no
     * banco, arquivos físicos no disco).
     *
     * Por que não confiar só na FK cascadeOnDelete:
     *   1. A FK atual é `nullOnDelete` (escolha consciente — evita perda
     *      acidental se alguém apagar empreendimento errado). Se mudasse
     *      pra cascade no DB, ainda assim ficariam os arquivos físicos
     *      órfãos no disco — o DB não sabe varrer storage/app/media/*.
     *   2. Aqui no PHP a gente coleta TODOS os storage_paths recursivos
     *      ANTES de apagar a pasta (cuja cascade do parent_id detona
     *      subpastas + media_files), e DEPOIS apaga os arquivos físicos.
     *
     * Chamado pelo EmpreendimentoObserver::deleting() — antes do delete
     * do empreendimento, enquanto a relação `empreendimento_id` ainda
     * existe na pasta.
     */
    public function handleEmpreendimentoDeleted(int $empreendimentoId): void
    {
        $folder = MediaFolder::where('empreendimento_id', $empreendimentoId)->first();
        if (!$folder) return; // empreendimento sem pasta — nada a fazer

        // 1) Coleta todos os storage_paths antes de apagar (depois do
        //    delete da pasta-mãe, os media_files já não existem mais).
        $paths = $this->collectFilePathsRecursive($folder);

        // 2) Apaga a pasta — a cascadeOnDelete da FK parent_id derruba
        //    subpastas e media_files automaticamente no banco.
        //    forceDelete() não é necessário (não tem soft delete aqui),
        //    mas usamos delete() padrão.
        $folder->delete();

        // 3) Limpa arquivos físicos do disco (silencioso — se um arquivo
        //    já não existe, Storage::delete ignora). Faz em batch.
        if (!empty($paths)) {
            Storage::disk('local')->delete($paths);
        }
    }

    /**
     * Coleta storage_paths de TODOS os arquivos descendentes de uma
     * pasta (filhos diretos + subpastas recursivamente).
     */
    private function collectFilePathsRecursive(MediaFolder $folder): array
    {
        $paths = $folder->files()->pluck('storage_path')->all();
        foreach ($folder->children as $child) {
            $paths = array_merge($paths, $this->collectFilePathsRecursive($child));
        }
        return $paths;
    }


    /* ====================================================================
     * LEADS — espelho de pasta por lead, restrito ao corretor responsável
     * ====================================================================
     * Mesmo padrão do empreendimento, mas com semântica de acesso pessoal:
     *   /LEADS/                  (raiz system)
     *   /LEADS/Maria Silva (#42)/  (lead_id=42, is_system=true)
     *
     * O MediaController filtra: pasta com lead_id só aparece pra admin/
     * gestor OU corretor cujo lead.assigned_user_id é ele mesmo. Se o
     * lead for reatribuído, o filtro reage (lê assigned_user_id em runtime).
     * ==================================================================== */

    /**
     * Garante que existe a pasta raiz "LEADS". Igual à raiz EMPREENDIMENTOS.
     */
    public function ensureLeadsRootFolder(): MediaFolder
    {
        return MediaFolder::firstOrCreate(
            [
                'parent_id'         => null,
                'empreendimento_id' => null,
                'lead_id'           => null,
                'name'              => self::ROOT_LEADS_FOLDER_NAME,
            ],
            [
                'description' => 'Materiais organizados por lead. Cada subpasta é gerenciada automaticamente — apenas o corretor responsável pelo lead (e admin/gestor) enxerga o conteúdo.',
                'is_system'   => true,
                'created_by'  => null,
            ]
        );
    }

    /**
     * Garante que existe a subpasta deste lead dentro da raiz LEADS.
     * Idempotente: se já existe (mesmo lead_id), só renomeia se o nome
     * do lead mudou.
     *
     * Nome inclui o ID do lead pra evitar colisão entre leads de mesmo
     * nome ("Maria Silva" pode aparecer várias vezes na base): formato
     * "Maria Silva (#42)".
     */
    public function ensureFolderForLead(Lead $lead): MediaFolder
    {
        $root = $this->ensureLeadsRootFolder();
        $desiredName = $this->buildLeadFolderName($lead);

        $folder = MediaFolder::where('lead_id', $lead->id)->first();

        if (!$folder) {
            return MediaFolder::create([
                'parent_id'   => $root->id,
                'lead_id'     => $lead->id,
                'name'        => $desiredName,
                'description' => 'Pasta automática do lead ' . $lead->name . '. Visível apenas pro corretor responsável e admin/gestor.',
                'is_system'   => true,
                'created_by'  => null,
            ]);
        }

        // Sincroniza se o nome do lead mudou ou alguém moveu pra fora da raiz
        $changes = [];
        if ($folder->name !== $desiredName) $changes['name'] = $desiredName;
        if ((int) $folder->parent_id !== (int) $root->id) $changes['parent_id'] = $root->id;
        if (!$folder->is_system) $changes['is_system'] = true;
        if ($changes) {
            $folder->update($changes);
        }
        return $folder;
    }

    private function buildLeadFolderName(Lead $lead): string
    {
        $name = trim((string) $lead->name) ?: ('Lead #' . $lead->id);
        // Limita pra não estourar varchar(200) com nomes monstros
        if (mb_strlen($name) > 160) {
            $name = mb_substr($name, 0, 157) . '...';
        }
        return $name . ' (#' . $lead->id . ')';
    }

    /**
     * Quando o lead é deletado, apaga a pasta + subpastas + arquivos
     * físicos do disco. Mesma estratégia de handleEmpreendimentoDeleted:
     * coleta paths antes do delete (cascade do parent_id derruba files
     * no DB), depois limpa do disco.
     *
     * Chamado pelo LeadObserver::deleting() — ANTES do delete real, com
     * a relação `lead_id` ainda viva (se rodasse depois, FK nullOnDelete
     * já teria zerado).
     */
    public function handleLeadDeleted(int $leadId): void
    {
        $folder = MediaFolder::where('lead_id', $leadId)->first();
        if (!$folder) return;

        $paths = $this->collectFilePathsRecursive($folder);
        $folder->delete();
        if (!empty($paths)) {
            Storage::disk('local')->delete($paths);
        }
    }
}
