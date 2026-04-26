<?php

namespace App\Services;

use App\Models\Empreendimento;
use App\Models\Lead;
use App\Models\MediaFile;
use App\Models\MediaFolder;
use Illuminate\Support\Facades\Storage;

class MediaLibrarySync
{
    public const ROOT_FOLDER_NAME       = 'EMPREENDIMENTOS';
    public const ROOT_LEADS_FOLDER_NAME = 'LEADS';

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

    public function ensureFolderForEmpreendimento(Empreendimento $emp): MediaFolder
    {
        $root = $this->ensureRootFolder();

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

        $changes = [];
        if ($folder->name !== $emp->name) {
            $changes['name'] = $emp->name;
        }

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

    public function handleEmpreendimentoDeleted(int $empreendimentoId): void
    {
        $folder = MediaFolder::where('empreendimento_id', $empreendimentoId)->first();
        if (!$folder) return;

        $paths = $this->collectFilePathsRecursive($folder);

        $folder->delete();

        if (!empty($paths)) {
            Storage::disk('local')->delete($paths);
        }
    }

    private function collectFilePathsRecursive(MediaFolder $folder): array
    {
        $paths = $folder->files()->pluck('storage_path')->all();
        foreach ($folder->children as $child) {
            $paths = array_merge($paths, $this->collectFilePathsRecursive($child));
        }
        return $paths;
    }

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

        if (mb_strlen($name) > 160) {
            $name = mb_substr($name, 0, 157) . '...';
        }
        return $name . ' (#' . $lead->id . ')';
    }

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
