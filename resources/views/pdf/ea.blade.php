@extends('pdf.layout')
@section('title', __('payroll.pdf.ea.title').' '.$form->serial_no)
@php
    use App\Domain\Payroll\Pdf\PayrollPdf as P;
    $boxes = (array) ($data['remuneration'] ?? []);
    $exempt = (array) ($data['tax_exempt'] ?? []);
@endphp
@section('content')
<h1>{{ __('payroll.pdf.ea.title') }}</h1>
<div class="muted">{{ __('payroll.pdf.ea.subtitle', ['year' => $form->tax_year]) }} &middot; {{ $form->serial_no }}</div>
@if ($form->status->value !== 'published')<div class="notice" style="margin-top:6px">{{ __('payroll.pdf.ea.not_published') }}</div>@endif
<div class="notice" style="margin-top:6px">{{ __('payroll.pdf.ea.layout_notice') }}</div>
<br>
<table class="box">
    <tr><td colspan="2"><h2>{{ __('payroll.pdf.ea.part_a') }}</h2></td></tr>
    <tr><td style="width:30%" class="muted">{{ __('payroll.pdf.ea.employer_name') }}</td><td>{{ $data['employer']['name'] ?? '' }}</td></tr>
    <tr><td class="muted">{{ __('payroll.pdf.ea.employer_no') }}</td><td>{{ $data['employer']['lhdn_employer_no'] ?? '—' }}</td></tr>
    <tr><td class="muted">{{ __('payroll.pdf.ea.employer_address') }}</td><td>{{ $data['employer']['address'] ?? '' }}</td></tr>
</table>
<br>
<table class="box">
    <tr><td colspan="2"><h2>{{ __('payroll.pdf.ea.part_b') }}</h2></td></tr>
    <tr><td style="width:30%" class="muted">{{ __('payroll.pdf.slip.name') }}</td><td>{{ $data['employee']['full_name'] ?? '' }}</td></tr>
    <tr><td class="muted">{{ __('payroll.pdf.slip.employee_no') }}</td><td>{{ $data['employee']['employee_no'] ?? '' }}</td></tr>
    <tr><td class="muted">{{ __('payroll.pdf.slip.ic') }}</td><td>{{ $data['employee']['ic_no'] ?? ($data['employee']['passport_no'] ?? '—') }}</td></tr>
    <tr><td class="muted">{{ __('payroll.pdf.slip.tin') }}</td><td>{{ $data['employee']['tin'] ?? '—' }}</td></tr>
    <tr><td class="muted">{{ __('payroll.pdf.slip.designation') }}</td><td>{{ $data['employee']['designation'] ?? '—' }}</td></tr>
    <tr><td class="muted">{{ __('payroll.pdf.ea.period') }}</td><td>{{ P::date($data['employment']['from'] ?? null) }} – {{ P::date($data['employment']['to'] ?? null) }}</td></tr>
</table>
<br>
<table class="grid">
    <tr><th colspan="2">{{ __('payroll.pdf.ea.remuneration') }}</th></tr>
    @foreach ($boxes as $box => $amount)
        <tr><td>{{ __('payroll.enums.ea_box.'.$box) }}</td><td class="r">{{ P::money($amount) }}</td></tr>
    @endforeach
    <tr class="total"><td>{{ __('payroll.pdf.ea.gross') }}</td><td class="r">{{ P::money($data['gross'] ?? '0.00') }}</td></tr>
    @if (count($exempt))
        <tr><th colspan="2">{{ __('payroll.pdf.ea.exempt') }}</th></tr>
        @foreach ($exempt as $code => $amount)<tr><td>{{ $code }}</td><td class="r">{{ P::money($amount) }}</td></tr>@endforeach
    @endif
    <tr><th colspan="2">{{ __('payroll.pdf.ea.deductions') }}</th></tr>
    <tr><td>{{ __('payroll.pdf.ea.pcb') }}</td><td class="r">{{ P::money($data['pcb'] ?? '0.00') }}</td></tr>
    <tr><td>CP38</td><td class="r">{{ P::money($data['cp38'] ?? '0.00') }}</td></tr>
    <tr><td>{{ __('payroll.pdf.ea.zakat') }}</td><td class="r">{{ P::money($data['zakat'] ?? '0.00') }}</td></tr>
    <tr><td>{{ __('payroll.pdf.ea.epf') }}</td><td class="r">{{ P::money($data['epf_employee'] ?? '0.00') }}</td></tr>
    <tr><td>{{ __('payroll.pdf.ea.socso') }}</td><td class="r">{{ P::money($data['socso_employee'] ?? '0.00') }}</td></tr>
    <tr><td>{{ __('payroll.pdf.ea.eis') }}</td><td class="r">{{ P::money($data['eis_employee'] ?? '0.00') }}</td></tr>
    <tr><td>SKBBK</td><td class="r">{{ P::money($data['skbbk_employee'] ?? '0.00') }}</td></tr>
</table>
<br>
<div class="box small">{{ __('payroll.pdf.ea.certification', ['employer' => $data['employer']['name'] ?? '']) }}<br><br><br>
    {{ __('payroll.pdf.ea.signature') }}: ______________________ &nbsp;&nbsp; {{ __('payroll.pdf.ea.date') }}: ______________</div>
<div class="footer">{{ __('payroll.pdf.ea.footer') }}</div>
@endsection
