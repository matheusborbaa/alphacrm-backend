<?php

namespace App\Console\Commands;

use App\Models\EmpreendimentoImage;
use App\Services\ImageWatermarkService;
use Illuminate\Console\Command;

// Aplica watermark nas imagens já cadastradas. Pula as que já têm watermark_applied_at.
// --force reaplica tudo, mas atenção: empilha logos uns sobre os outros.
class ApplyImageWatermark extends Command
{
    protected $signature = 'images:apply-watermark
        {--chunk=20 : Quantas imagens processar por batch}
        {--empreendimento= : Limitar a um empreendimento específico (id)}
        {--force : Reaplicar mesmo nas que já têm marca d\'água}';

    protected $description = 'Aplica marca d\'água em imagens existentes do empreendimento (idempotente por padrão)';

    public function handle(ImageWatermarkService $service): int
    {
        if (!$service->isEnabled()) {
            $this->error('Marca d\'água não está habilitada.');
            $this->line('Verifique em Configurações → Geral → Marca d\'água:');
            $this->line('  - image_watermark_enabled = true');
            $this->line('  - image_watermark_logo_path setado e arquivo existe');
            return self::FAILURE;
        }

        $chunk = (int) $this->option('chunk');
        if ($chunk < 1 || $chunk > 200) $chunk = 20;

        $force = (bool) $this->option('force');
        $empId = $this->option('empreendimento');

        $query = EmpreendimentoImage::query();
        if (!$force) {
            $query->whereNull('watermark_applied_at');
        }
        if ($empId) {
            $query->where('empreendimento_id', (int) $empId);
        }

        $total = $query->count();
        if ($total === 0) {
            $this->info('Nenhuma imagem pra processar.');
            return self::SUCCESS;
        }

        $this->info("Processando {$total} imagem(ns) em chunks de {$chunk}...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $applied = 0;
        $skipped = 0;

        $query->orderBy('id')->chunk($chunk, function ($images) use ($service, &$applied, &$skipped, $bar) {
            foreach ($images as $img) {
                if ($service->apply($img->image_path)) {
                    $img->update(['watermark_applied_at' => now()]);
                    $applied++;
                } else {
                    $skipped++;
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("✓ Aplicadas: {$applied}");
        if ($skipped > 0) {
            $this->warn("Puladas (erro/path inválido): {$skipped} — ver storage/logs/laravel.log");
        }

        return self::SUCCESS;
    }
}
