<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Certificado — {{ $course->title }}</title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    @page { size: A4 landscape; margin: 0; }
    * { box-sizing: border-box; }
    html, body {
        margin: 0; padding: 0;
        background: #e2e8f0;
        font-family: 'Inter', -apple-system, sans-serif;
        color: #0f172a;
    }


    .cert-page {
        width: 297mm;
        height: 210mm;
        margin: 24px auto;
        background: #fffdf8;
        background-image:
            radial-gradient(circle at 0% 0%, rgba(30,64,175,.06), transparent 35%),
            radial-gradient(circle at 100% 100%, rgba(251,191,36,.08), transparent 35%);
        position: relative;
        box-shadow: 0 14px 40px rgba(15,23,42,.18);
        overflow: hidden;
    }


    .cert-border {
        position: absolute;
        inset: 14mm;
        border: 2px solid #1e3a8a;
        border-radius: 2px;
    }
    .cert-border-inner {
        position: absolute;
        inset: 16mm;
        border: 1px solid #fbbf24;
        border-radius: 2px;
    }


    .cert-corner {
        position: absolute;
        width: 70px; height: 70px;
        border: 4px solid #fbbf24;
        z-index: 1;
    }
    .cert-corner-tl { top: 18mm; left: 18mm;  border-right: none; border-bottom: none; }
    .cert-corner-tr { top: 18mm; right: 18mm; border-left:  none; border-bottom: none; }
    .cert-corner-bl { bottom: 18mm; left: 18mm;  border-right: none; border-top: none; }
    .cert-corner-br { bottom: 18mm; right: 18mm; border-left:  none; border-top: none; }


    .cert-watermark {
        position: absolute;
        top: 50%; left: 50%;
        transform: translate(-50%, -52%);
        font-family: 'Cormorant Garamond', serif;
        font-size: 200px;
        font-weight: 700;
        color: rgba(30, 64, 175, 0.05);
        letter-spacing: 14px;
        pointer-events: none;
        z-index: 0;
        white-space: nowrap;
    }


    .cert-content {
        position: absolute;
        inset: 22mm 26mm;
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
        z-index: 2;
    }


    .cert-top {
        display: flex; flex-direction: column; align-items: center;
        margin-bottom: auto;
    }
    .cert-org {
        font-size: 13px;
        letter-spacing: 8px;
        color: #64748b;
        text-transform: uppercase;
        font-weight: 600;
        margin-bottom: 18px;
    }
    .cert-divider {
        width: 60px; height: 3px;
        background: linear-gradient(90deg, transparent, #fbbf24, transparent);
        margin-bottom: 18px;
    }
    .cert-title {
        font-family: 'Cormorant Garamond', serif;
        font-size: 72px;
        font-weight: 700;
        color: #1e3a8a;
        line-height: 1;
        margin: 0 0 4px;
        letter-spacing: 1px;
    }
    .cert-subtitle {
        font-size: 14px;
        color: #94a3b8;
        font-style: italic;
        letter-spacing: 4px;
        text-transform: uppercase;
        margin-bottom: 0;
    }


    .cert-middle {
        display: flex; flex-direction: column; align-items: center;
        margin: auto 0;
    }
    .cert-presented-to {
        font-size: 12px;
        letter-spacing: 5px;
        color: #475569;
        text-transform: uppercase;
        margin-bottom: 14px;
        font-weight: 500;
    }
    .cert-name {
        font-family: 'Cormorant Garamond', serif;
        font-size: 56px;
        font-weight: 600;
        color: #0f172a;
        line-height: 1.05;
        margin: 0 0 6px;
        max-width: 90%;
    }
    .cert-name-underline {
        width: 70%;
        max-width: 600px;
        height: 1px;
        background: #cbd5e1;
        margin-bottom: 24px;
    }
    .cert-body-text {
        font-size: 15px;
        color: #334155;
        line-height: 1.7;
        max-width: 720px;
    }
    .cert-course-name {
        font-family: 'Cormorant Garamond', serif;
        font-size: 30px;
        font-weight: 600;
        color: #1e3a8a;
        font-style: italic;
        display: block;
        margin: 10px 0;
    }


    .cert-bottom {
        margin-top: auto;
        width: 100%;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 14px;
    }
    .cert-footer {
        display: grid;
        grid-template-columns: 1fr auto 1fr;
        align-items: end;
        gap: 30px;
        width: 100%;
    }
    .cert-foot-block {
        text-align: center;
        max-width: 280px;
        justify-self: center;
        width: 100%;
    }
    .cert-foot-block:first-child { justify-self: end; }
    .cert-foot-block:last-child  { justify-self: start; }
    .cert-foot-line {
        border-top: 1.5px solid #475569;
        padding-top: 6px;
        font-size: 12px;
        color: #1e293b;
        font-weight: 600;
    }
    .cert-foot-label {
        font-size: 9px;
        color: #94a3b8;
        text-transform: uppercase;
        letter-spacing: 2px;
        margin-top: 4px;
    }


    .cert-seal {
        width: 88px; height: 88px;
        border: 3px double #fbbf24;
        border-radius: 50%;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        background: rgba(251, 191, 36, 0.1);
        font-family: 'Cormorant Garamond', serif;
        color: #1e3a8a;
        text-align: center;
        line-height: 1;
        transform: rotate(-8deg);
    }
    .cert-seal-text {
        font-size: 9px;
        letter-spacing: 1.5px;
        text-transform: uppercase;
        margin-bottom: 4px;
        font-family: 'Inter', sans-serif;
        font-weight: 600;
    }
    .cert-seal-year {
        font-size: 22px;
        font-weight: 700;
        line-height: 1;
    }


    .cert-cert-number {
        font-size: 9px;
        color: #94a3b8;
        letter-spacing: 2px;
        text-transform: uppercase;
        text-align: center;
    }


    .print-bar {
        position: fixed;
        top: 0; left: 0; right: 0;
        background: #1e3a8a;
        color: #fff;
        padding: 12px 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        z-index: 9999;
        font-size: 13px;
        box-shadow: 0 2px 12px rgba(0,0,0,.2);
    }
    .print-bar-info { display: flex; align-items: center; gap: 8px; }
    .print-bar button {
        background: #fff;
        color: #1e3a8a;
        border: none;
        padding: 8px 18px;
        border-radius: 6px;
        font-weight: 600;
        cursor: pointer;
        font-size: 13px;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: transform .12s;
    }
    .print-bar button:hover { transform: translateY(-1px); }


    @media print {
        body { background: #fff; }
        .cert-page { margin: 0; box-shadow: none; }
        .print-bar { display: none !important; }
    }
</style>
</head>
<body>

<div class="print-bar">
    <span class="print-bar-info">
        <span style="font-size:18px;">📄</span>
        Certificado pronto. Use Ctrl+P (ou ⌘+P no Mac) e escolha "Salvar como PDF".
    </span>
    <button onclick="window.print()">🖨️ Imprimir / Salvar PDF</button>
</div>

<div class="cert-page">

    <div class="cert-watermark">CERTIFICADO</div>


    <div class="cert-border"></div>
    <div class="cert-border-inner"></div>
    <div class="cert-corner cert-corner-tl"></div>
    <div class="cert-corner cert-corner-tr"></div>
    <div class="cert-corner cert-corner-bl"></div>
    <div class="cert-corner cert-corner-br"></div>


    <div class="cert-content">


        <div class="cert-top">
            <div class="cert-org">{{ strtoupper($companyName) }}</div>
            <h1 class="cert-title">Certificado</h1>
            <div class="cert-subtitle">de Conclusão</div>
            <div class="cert-divider" style="margin-top:14px;margin-bottom:0;"></div>
        </div>


        <div class="cert-middle">
            <div class="cert-presented-to">Concedido a</div>
            <div class="cert-name">{{ $user->name }}</div>
            <div class="cert-name-underline"></div>
            <div class="cert-body-text">
                por concluir com aproveitamento o curso
                <span class="cert-course-name">"{{ $course->title }}"</span>
                @if($course->has_quiz)
                    tendo sido aprovado(a) no quiz com pontuação igual ou superior à exigida pelo programa.
                @else
                    tendo assistido a todo o conteúdo do programa.
                @endif
            </div>
        </div>


        <div class="cert-bottom">
            <div class="cert-footer">
                <div class="cert-foot-block">
                    <div class="cert-foot-line">{{ $cert->issued_at->locale('pt_BR')->isoFormat('D [de] MMMM [de] YYYY') }}</div>
                    <div class="cert-foot-label">Data de Emissão</div>
                </div>

                <div class="cert-seal">
                    <span class="cert-seal-text">Certificado</span>
                    <span class="cert-seal-year">{{ $cert->issued_at->format('Y') }}</span>
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

    </div>
</div>

<script>

setTimeout(() => {
    try { window.print(); } catch(e) {}
}, 800);
</script>

</body>
</html>
