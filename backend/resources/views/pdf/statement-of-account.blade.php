<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Statement of Account - {{ $statement['student']['student_number'] }}</title>
    <style>
        @page { size: a4 portrait; margin: 12mm 12mm; }
        {{-- DejaVu Sans (bundled with DomPDF) has a Peso-sign glyph; Helvetica/Arial do not and print "?". --}}
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #000; margin: 0; }
        h1 { font-size: 14pt; text-align: center; margin: 0 0 2pt 0; }
        .sub { text-align: center; font-size: 8pt; margin-bottom: 8pt; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 8pt; }
        th, td { border: 0.5pt solid #444; padding: 2.5pt 4pt; vertical-align: top; }
        th { background: #eee; text-align: left; }
        .right { text-align: right; }
        .term-title { font-weight: bold; font-size: 10pt; margin: 8pt 0 3pt 0; }
        .totals td { font-weight: bold; }
        .avoid-break { page-break-inside: avoid; }
        .muted { color: #555; }
    </style>
</head>
<body>
    <h1>STATEMENT OF ACCOUNT</h1>
    <p class="sub">Generated {{ $statement['generated_at'] }}</p>

    <table>
        <tr>
            <th style="width: 22%">Student No.</th>
            <td>{{ $statement['student']['student_number'] }}</td>
            <th style="width: 14%">Name</th>
            <td>{{ $statement['student']['name'] }}</td>
        </tr>
        <tr>
            <th>Degree</th>
            <td colspan="3">{{ $statement['student']['program_code'] }} - {{ $statement['student']['program_name'] }}</td>
        </tr>
    </table>

    @forelse ($statement['terms'] as $term)
        <div class="avoid-break">
            <p class="term-title">{{ $term['label'] }}</p>
            <table>
                <thead>
                    <tr><th>Assessment</th><th class="right" style="width: 22%">Amount</th></tr>
                </thead>
                <tbody>
                    @foreach ($term['lines'] as $line)
                        <tr>
                            <td>{{ $line['label'] }}</td>
                            <td class="right">{{ number_format((float) $line['amount'], 2) }}</td>
                        </tr>
                    @endforeach
                    <tr class="totals">
                        <td>Total assessed</td>
                        <td class="right">{{ number_format((float) $term['assessment_total'], 2) }}</td>
                    </tr>
                </tbody>
            </table>
            <table>
                <thead>
                    <tr><th>Payment</th><th style="width: 22%">Reference</th><th class="right" style="width: 22%">Amount</th></tr>
                </thead>
                <tbody>
                    @forelse ($term['payments'] as $payment)
                        <tr>
                            <td>
                                {{ $payment['label'] }}
                                @if ($payment['promissory_note_on_file']) <span class="muted">(promissory note on file)</span> @endif
                            </td>
                            <td>{{ $payment['reference_number'] }}</td>
                            <td class="right">{{ number_format((float) $payment['amount'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="muted">No payment recorded for this term.</td></tr>
                    @endforelse
                    <tr class="totals">
                        <td colspan="2">Balance for this term</td>
                        <td class="right">{{ number_format((float) $term['outstanding'], 2) }}</td>
                    </tr>
                    <tr>
                        <td colspan="2">Balance brought forward</td>
                        <td class="right">{{ number_format((float) $term['prior_balance'], 2) }}</td>
                    </tr>
                    <tr class="totals">
                        <td colspan="2">Running balance</td>
                        <td class="right">{{ number_format((float) $term['running_balance'], 2) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    @empty
        <p class="muted">No assessment has been issued yet.</p>
    @endforelse

    @if (count($statement['credits']) > 0)
        <p class="term-title">Advance payments</p>
        <table>
            <tbody>
                @foreach ($statement['credits'] as $credit)
                    <tr>
                        <td>{{ $credit['reference_number'] }}</td>
                        <td class="right">{{ number_format((float) $credit['amount'], 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table class="totals">
        <tr><td>Total assessed</td><td class="right">{{ number_format((float) $statement['summary']['total_assessed'], 2) }}</td></tr>
        <tr><td>Total paid</td><td class="right">{{ number_format((float) $statement['summary']['total_paid'], 2) }}</td></tr>
        <tr><td>Outstanding balance</td><td class="right">{{ number_format((float) $statement['summary']['outstanding_balance'], 2) }}</td></tr>
        @if ((float) $statement['summary']['advance_payment_balance'] > 0)
            <tr><td>Advance payment on account</td><td class="right">{{ number_format((float) $statement['summary']['advance_payment_balance'], 2) }}</td></tr>
        @endif
    </table>
</body>
</html>
