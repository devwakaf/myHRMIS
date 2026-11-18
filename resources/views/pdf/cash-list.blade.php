@extends('pdf.layout')
@section('title', __('payroll.pdf.cash.title'))
@php use App\Domain\Payroll\Pdf\PayrollPdf as P; @endphp
@section('content')
<h1>{{ __('payroll.pdf.cash.title') }}</h1>
<div>{{ $org?->name }} &middot; {{ P::monthName($run->period->format('Y-m')) }} &middot; {{ $run->number }}</div>
<div class="small muted">{{ __('payroll.pdf.slip.payment_date') }}: {{ P::date($run->payment_date->toDateString()) }}</div>
<br>
<table class="grid">
    <tr><th>#</th><th>{{ __('payroll.pdf.slip.employee_no') }}</th><th>{{ __('payroll.pdf.slip.name') }}</th><th>{{ __('payroll.pdf.cash.mode') }}</th><th class="r">{{ __('payroll.pdf.slip.net') }}</th><th>{{ __('payroll.pdf.cash.signature') }}</th></tr>
    @forelse ($rows as $i => $r)
        <tr><td>{{ $i + 1 }}</td><td>{{ $r['employee_no'] }}</td><td>{{ $r['full_name'] }}</td><td>{{ __('payroll.pdf.cash.modes.'.$r['payment_mode']) }}</td><td class="r">{{ P::money($r['net_pay']) }}</td><td style="width:22%">&nbsp;</td></tr>
    @empty
        <tr><td colspan="6" class="c muted">{{ __('payroll.pdf.cash.none') }}</td></tr>
    @endforelse
    <tr class="total"><td colspan="4">{{ __('payroll.pdf.total') }}</td><td class="r">{{ P::money($total) }}</td><td></td></tr>
</table>
<div class="footer">{{ __('payroll.pdf.cash.footer') }}</div>
@endsection
