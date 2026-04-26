<?php

namespace App\Services;

use App\Models\Empreendimento;
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
    public const ROOT_FOLDER_NAME = 'EMPREENDIMENTOS';

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
}
