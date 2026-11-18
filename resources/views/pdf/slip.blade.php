@extends('pdf.layout')
@section('title', __('payroll.pdf.slip.title').' '.$slip->number)
@php
    use App\Domain\Payroll\Pdf\PayrollPdf as P;
    use App\Domain\Shared\Money\Num;
    $period = $slip->period->format('Y-m');
    $status = $slip->status->value;
    $ribbon = $status === 'draft' ? 'draft' : ($status === 'cancelled' ? 'cancelled' : ($slip->payment_status->value === 'withheld' ? 'withheld' : null));
    $trim = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0';
@endphp
@section('content')
<table class="head">
    <tr>
        <td style="width:62%">
            <h1>{{ $org?->name }}</h1>
            <div class="small muted">
                {{ trim(implode(', ', array_filter([$org?->address_line1, $org?->address_line2, $org?->address_line3, trim(($org?->postcode ?? '').' '.($org?->city ?? ''))]))) }}<br>
                @if ($org?->kwsp_employer_no) {{ __('payroll.pdf.slip.kwsp_no') }}: {{ $org->kwsp_employer_no }} &nbsp; @endif
                @if ($org?->perkeso_employer_code) {{ __('payroll.pdf.slip.perkeso_no') }}: {{ $org->perkeso_employer_code }} &nbsp; @endif
                @if ($org?->lhdn_employer_no) {{ __('payroll.pdf.slip.lhdn_no') }}: {{ $org->lhdn_employer_no }} @endif
            </div>
        </td>
        <td class="r">
            <h1>{{ __('payroll.pdf.slip.title') }}</h1>
            <div>{{ P::monthName($period) }}</div>
            <div class="small muted">{{ $slip->number }}</div>
            <div class="small">{{ __('payroll.pdf.slip.payment_date') }}: {{ P::date($slip->payment_date->toDateString()) }}</div>
        </td>
    </tr>
</table>
<br>
@if ($ribbon)<div class="ribbon">{{ __('payroll.pdf.slip.ribbon.'.$ribbon) }}</div>@endif

<table class="box" style="margin-bottom:6px">
    <tr>
        <td style="width:50%">
            <span class="muted">{{ __('payroll.pdf.slip.name') }}</span><br><b>{{ $slip->full_name }}</b>
        </td>
        <td style="width:25%"><span class="muted">{{ __('payroll.pdf.slip.employee_no') }}</span><br>{{ $slip->employee_no }}</td>
        <td><span class="muted">{{ __('payroll.pdf.slip.ic') }}</span><br>{{ $ic ?? $slip->passport_no }}</td>
    </tr>
    <tr>
        <td><span class="muted">{{ __('payroll.pdf.slip.designation') }}</span><br>{{ $designation ?? '—' }}</td>
        <td><span class="muted">{{ __('payroll.pdf.slip.department') }}</span><br>{{ $department ?? '—' }}</td>
        <td><span class="muted">{{ __('payroll.pdf.slip.tin') }}</span><br>{{ $slip->tin ?: '—' }}</td>
    </tr>
    <tr>
        <td><span class="muted">{{ __('payroll.pdf.slip.kwsp_member') }}</span> {{ $slip->kwsp_no ?: '—' }} &nbsp;
            <span class="muted">{{ __('payroll.pdf.slip.perkeso_member') }}</span> {{ $slip->perkeso_no ?: '—' }}</td>
        <td colspan="2"><span class="muted">{{ __('payroll.pdf.slip.bank') }}</span>
            @if ($bankLast4) ****{{ $bankLast4 }} @else — @endif</td>
    </tr>
</table>

<table>
    <tr>
        <td style="width:50%; padding-right:8px">
            <h2>{{ __('payroll.pdf.slip.earnings') }}</h2>
            <table class="grid">
                @foreach ($earnings as $l)
                    <tr><td>{{ $l->name_ms }}@if ($l->note)<br><span class="small muted">{{ $l->note }}</span>@endif</td><td class="r">{{ P::money($l->amount) }}</td></tr>
                @endforeach
                <tr class="total"><td>{{ __('payroll.pdf.slip.gross') }}</td><td class="r">{{ P::money($slip->gross_pay) }}</td></tr>
            </table>
        </td>
        <td style="width:50%; padding-left:8px">
            <h2>{{ __('payroll.pdf.slip.deductions') }}</h2>
            <table class="grid">
                @foreach ($deductions as $l)
                    <tr><td>{{ $l->name_ms }}@if ($l->note)<br><span class="small muted">{{ $l->note }}</span>@endif</td><td class="r">{{ P::money($l->amount) }}</td></tr>
                @endforeach
                <tr class="total"><td>{{ __('payroll.pdf.slip.total_deductions') }}</td><td class="r">{{ P::money($slip->total_deductions) }}</td></tr>
            </table>
        </td>
    </tr>
</table>
<br>
<table class="net"><tr><td style="padding:6px">{{ __('payroll.pdf.slip.net') }}</td><td class="r" style="padding:6px">{{ P::money($slip->net_pay) }}</td></tr></table>
@if ((float) $slip->net_rounding_adjustment != 0)
    <div class="small muted r">{{ __('payroll.pdf.slip.rounding') }}: {{ P::money($slip->net_rounding_adjustment) }}</div>
@endif
<br>
<table>
    <tr>
        @if ($settings->show_employer_contributions && $employer->isNotEmpty())
        <td style="width:50%; padding-right:8px">
            <div class="box">
                <h2>{{ __('payroll.pdf.slip.employer_box') }}</h2>
                <table>
                    @foreach ($employer as $l)<tr><td>{{ $l->name_ms }}</td><td class="r">{{ P::money($l->amount) }}</td></tr>@endforeach
                </table>
            </div>
        </td>
        @endif
        <td style="padding-left:8px">
            <div class="box">
                <h2>{{ __('payroll.pdf.slip.days_box') }}</h2>
                <table>
                    <tr><td>{{ __('payroll.pdf.slip.working_days') }}</td><td class="r">{{ $slip->working_days === null ? '—' : $trim($slip->working_days) }}</td></tr>
                    <tr><td>{{ __('payroll.pdf.slip.lwp_days') }}</td><td class="r">{{ $trim($slip->lwp_days) }}</td></tr>
                    <tr><td>{{ __('payroll.pdf.slip.payment_days') }}</td><td class="r">{{ $trim($slip->payment_days) }}</td></tr>
                </table>
            </div>
        </td>
    </tr>
</table>
@if ($settings->show_ytd && $ytd)
<br>
<div class="box">
    <h2>{{ __('payroll.pdf.slip.ytd_box') }}</h2>
    <table>
        <tr>
            <td>{{ __('payroll.pdf.slip.ytd_gross') }}<br><b>{{ P::money(Num::money(Num::add($ytd['y_normal'] ?? '0', $ytd['y_additional'] ?? '0'))) }}</b></td>
            <td>{{ __('payroll.pdf.slip.ytd_epf') }}<br><b>{{ P::money($ytd['k'] ?? '0.00') }}</b></td>
            <td>{{ __('payroll.pdf.slip.ytd_pcb') }}<br><b>{{ P::money($ytd['x'] ?? '0.00') }}</b></td>
            <td>{{ __('payroll.pdf.slip.ytd_zakat') }}<br><b>{{ P::money($ytd['z'] ?? '0.00') }}</b></td>
        </tr>
    </table>
</div>
@endif
<div class="footer">{{ __('payroll.pdf.slip.footer') }}</div>
@endsection
