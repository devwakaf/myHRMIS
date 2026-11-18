@extends('pdf.layout')
@section('title', __('payroll.pdf.pcb2ii.title'))
@php use App\Domain\Payroll\Pdf\PayrollPdf as P; @endphp
@section('content')
<h1>{{ __('payroll.pdf.pcb2ii.title') }}</h1>
<div class="muted">{{ __('payroll.pdf.pcb2ii.subtitle', ['year' => $data['year']]) }}</div>
<br>
<table class="box">
    <tr><td style="width:30%" class="muted">{{ __('payroll.pdf.ea.employer_name') }}</td><td>{{ $org?->name }}</td></tr>
    <tr><td class="muted">{{ __('payroll.pdf.ea.employer_no') }}</td><td>{{ $org?->lhdn_employer_no ?: '—' }}</td></tr>
    <tr><td class="muted">{{ __('payroll.pdf.slip.name') }}</td><td>{{ $employee['full_name'] }} ({{ $employee['employee_no'] }})</td></tr>
    <tr><td class="muted">{{ __('payroll.pdf.slip.tin') }}</td><td>{{ $employee['tin'] ?: '—' }}</td></tr>
</table>
<br>
<table class="grid">
    <tr><th>{{ __('payroll.pdf.pcb2ii.month') }}</th><th class="r">PCB</th><th class="r">CP38</th><th>{{ __('payroll.pdf.pcb2ii.receipt') }}</th><th>{{ __('payroll.pdf.pcb2ii.paid_on') }}</th></tr>
    @forelse ($data['months'] as $m)
        <tr><td>{{ P::monthName($m['period']) }}</td><td class="r">{{ P::money($m['pcb']) }}</td><td class="r">{{ P::money($m['cp38']) }}</td><td>{{ $m['receipt_no'] ?: '—' }}</td><td>{{ P::date($m['paid_on']) }}</td></tr>
    @empty
        <tr><td colspan="5" class="c muted">{{ __('payroll.pdf.pcb2ii.none') }}</td></tr>
    @endforelse
    <tr class="total"><td>{{ __('payroll.pdf.total') }}</td><td class="r">{{ P::money($data['total_pcb']) }}</td><td class="r">{{ P::money($data['total_cp38']) }}</td><td colspan="2"></td></tr>
</table>
<br>
<div class="box small">{{ __('payroll.pdf.pcb2ii.certification', ['employer' => $org?->name]) }}<br><br><br>
    {{ __('payroll.pdf.ea.signature') }}: ______________________ &nbsp;&nbsp; {{ __('payroll.pdf.ea.date') }}: ______________</div>
<div class="footer">{{ __('payroll.pdf.pcb2ii.footer') }}</div>
@endsection
