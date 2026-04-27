<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Certificado — {{ $course->title }}</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;700;900&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
    @page {
        size: A4 landscape;
        margin: 0;
    }
    * { box-sizing: border-box; }
    html, body {
        margin: 0;
        padding: 0;
        background: #f1f5f9;
        font-family: 'Inter', -apple-system, sans-serif;
    }

    .cert-page {
        width: 297mm;
        height: 210mm;
        margin: 20px auto;
        background: #fff;
        position: relative;
        box-shadow: 0 10px 40px rgba(0,0,0,.15);
        overflow: hidden;
    }


    .cert-border {
        position: absolute;
        inset: 18px;
        border: 4px double #1e40af;
        border-radius: 4px;
    }

    .cert-corner {
        position: absolute;
        width: 90px;
        height: 90px;
        border: 6px solid #fbbf24;
    }
    .cert-corner-tl { top: 30px; left: 30px;  border-right: none; border-bottom: none; }
    .cert-corner-tr { top: 30px; right: 30px; border-left:  none; border-bottom: none; }
    .cert-corner-bl { bottom: 30px; left: 30px;  border-right: none; border-top: none; }
    .cert-corner-br { bottom: 30px; right: 30px; border-left:  none; border-top: none; }


    .cert-content {
        position: absolute;
        inset: 60px;
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
        padding: 40px 60px;
    }

    .cert-header {
        font-size: 14px;
        letter-spacing: 6px;
        color: #64748b;
        text-transform: uppercase;
        margin-bottom: 10px;
        font-weight: 600;
    }

    .cert-title {
        font-family: 'Playfair Display', serif;
        font-size: 56px;
        font-weight: 900;
        color: #1e3a8a;
        line-height: 1;
        margin-bottom: 8px;
        letter-spacing: -1px;
    }

    .cert-subtitle {
        font-size: 14px;
        color: #94a3b8;
        font-style: italic;
        margin-bottom: 36px;
    }

    .cert-presented-to {
        font-size: 13px;
        letter-spacing: 4px;
        color: #475569;
        text-transform: uppercase;
        margin-bottom: 12px;
    }

    .cert-name {
        font-family: 'Playfair Display', serif;
        font-size: 48px;
        font-weight: 700;
        color: #0f172a;
        border-bottom: 2px solid #cbd5e1;
        padding-bottom: 8px;
        min-width: 60%;
        margin-bottom: 28px;
        line-height: 1.1;
    }

    .cert-body-text {
        font-size: 16px;
        color: #334155;
        line-height: 1.8;
        max-width: 80%;
        margin-bottom: 30px;
    }
    .cert-body-text strong { color: #1e3a8a; font-weight: 600; }

    .cert-course-name {
        font-family: 'Playfair Display', serif;
        font-size: 28px;
        font-weight: 700;
        color: #1e3a8a;
        font-style: italic;
        margin: 12px 0;
    }


    .cert-footer {
        position: absolute;
        bottom: 80px;
        left: 80px;
        right: 80px;
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
    }

    .cert-foot-block {
        text-align: center;
        flex: 1;
    }
    .cert-foot-line {
        border-top: 1.5px solid #475569;
        padding-top: 8px;
        margin: 0 30px;
        font-size: 12px;
        color: #475569;
        font-weight: 500;
    }
    .cert-foot-label {
        font-size: 10px;
        color: #94a3b8;
        text-transform: uppercase;
        letter-spacing: 1.5px;
        margin-top: 2px;
    }

    .cert-seal {
        position: absolute;
        right: 80px;
        bottom: 130px;
        width: 110px;
        height: 110px;
        border: 4px double #fbbf24;
        border-radius: 50%;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        background: rgba(251, 191, 36, 0.08);
        font-family: 'Playfair Display', serif;
        color: #1e40af;
        text-align: center;
        line-height: 1;
        transform: rotate(-12deg);
    }
    .cert-seal-text {
        font-size: 10px;
        letter-spacing: 2px;
        text-transform: uppercase;
        margin-bottom: 4px;
    }
    .cert-seal-year {
        font-size: 22px;
        font-weight: 900;
    }


    .cert-watermark {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        font-family: 'Playfair Display', serif;
        font-size: 220px;
        font-weight: 900;
        color: rgba(30, 64, 175, 0.04);
        letter-spacing: 12px;
        pointer-events: none;
        z-index: 0;
    }


    .cert-cert-number {
        position: absolute;
        bottom: 30px;
        left: 50%;
        transform: translateX(-50%);
        font-size: 10px;
        color: #94a3b8;
        letter-spacing: 2px;
        text-transform: uppercase;
    }


    .print-bar {
        position: fixed;
        top: 0; left: 0; right: 0;
        background: #1e40af;
        color: #fff;
        padding: 12px 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        z-index: 9999;
        font-size: 14px;
        box-shadow: 0 2px 10px rgba(0,0,0,.2);
    }
    .print-bar button {
        background: #fff;
        color: #1e40af;
        border: none;
        padding: 8px 16px;
        border-radius: 6px;
        font-weight: 600;
        cursor: pointer;
        font-size: 13px;
    }
    .print-bar button:hover { background: #f1f5f9; }


    @media print {
        body { background: #fff; }
        .cert-page { margin: 0; box-shadow: none; }
        .print-bar { display: none !important; }
    }
</style>
</head>
<body>

<div class="print-bar">
    <span>📄 Certificado pronto. Use Ctrl+P (ou ⌘+P no Mac) e escolha "Salvar como PDF".</span>
    <button onclick="window.print()">🖨️ Imprimir / Salvar PDF</button>
</div>

<div class="cert-page">


    <div class="cert-watermark">CERTIFICADO</div>


    <div class="cert-border"></div>
    <div class="cert-corner cert-corner-tl"></div>
    <div class="cert-corner cert-corner-tr"></div>
    <div class="cert-corner cert-corner-bl"></div>
    <div class="cert-corner cert-corner-br"></div>


    <div class="cert-seal">
        <span class="cert-seal-text">Certificado</span>
        <span class="cert-seal-year">{{ $cert->issued_at->format('Y') }}</span>
    </div>

    <div class="cert-content">
        <div class="cert-header">{{ strtoupper($companyName) }}</div>
        <h1 class="cert-title">Certificado</h1>
        <div class="cert-subtitle">de Conclusão de Curso</div>

        <div class="cert-presented-to">Concedido a</div>
        <div class="cert-name">{{ $user->name }}</div>

        <div class="cert-body-text">
            por concluir com aproveitamento o curso
            <div class="cert-course-name">"{{ $course->title }}"</div>
            @if($course->has_quiz)
                tendo sido aprovado no quiz com pontuação igual ou superior à exigida pelo programa.
            @else
                tendo assistido ao conteúdo na sua totalidade.
            @endif
        </div>
    </div>


    <div class="cert-footer">
        <div class="cert-foot-block">
            <div class="cert-foot-line">{{ $cert->issued_at->locale('pt_BR')->isoFormat('D [de] MMMM [de] YYYY') }}</div>
            <div class="cert-foot-label">Data de Emissão</div>
        </div>
        <div class="cert-foot-block">
            <div class="cert-foot-line">{{ $companyName }}</div>
            <div class="cert-foot-label">Instituição Emissora</div>
        </div>
    </div>


    <div class="cert-cert-number">
        Certificado nº {{ $cert->certificate_number }}
    </div>

</div>

<script>

setTimeout(() => {
    try { window.print(); } catch(e) {}
}, 800);
</script>

</body>
</html>
