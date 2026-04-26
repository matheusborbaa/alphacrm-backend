<?php

namespace App\Services;

use App\Models\Empreendimento;
use App\Models\MediaFolder;

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
     * Quando um empreendimento é deletado, decidimos NÃO apagar a pasta
     * automaticamente. A FK em media_folders.empreendimento_id é
     * nullOnDelete, então a pasta vira "órfã" — vira pasta global com
     * `empreendimento_id=null` e mantém os arquivos. Admin pode revisar
     * e apagar manualmente se quiser.
     *
     * Esse método existe pra deixar explícita a intenção (e cobrir casos
     * futuros se a regra mudar). Por ora é noop — só registra.
     */
    public function handleEmpreendimentoDeleted(int $empreendimentoId): void
    {
        // Noop intencional. Veja docblock acima.
    }
}
