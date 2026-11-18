@extends('pdf.layout')
@section('title', $title)
@php use App\Domain\Payroll\Pdf\PayrollPdf as P; @endphp
@section('content')
<h1>{{ $title }}</h1>
<div>{{ $org?->name }} &middot; {{ P::monthName($period) }}</div>
@if ($unverified)<div class="notice" style="margin-top:6px">{{ __('statutory.messages.unverified_banner') }}</div>@endif
<br>
<table class="grid">
    <tr>@foreach ($data['columns'] as $c)<th class="{{ ($c['money'] ?? false) ? 'r' : '' }}">{{ $c['label'] }}</th>@endforeach</tr>
    @foreach ($data['rows'] as $row)
        <tr>@foreach ($data['columns'] as $c)<td class="{{ ($c['money'] ?? false) ? 'r' : '' }}">{{ ($c['money'] ?? false) ? P::money($row[$c['key']] ?? '') : ($row[$c['key']] ?? '') }}</td>@endforeach</tr>
    @endforeach
    <tr class="total">@foreach ($data['columns'] as $i => $c)
        <td class="{{ ($c['money'] ?? false) ? 'r' : '' }}">{{ $i === 0 ? __('payroll.pdf.total') : (isset($data['totals'][$c['key']]) ? (($c['money'] ?? false) ? P::money($data['totals'][$c['key']]) : $data['totals'][$c['key']]) : '') }}</td>
    @endforeach</tr>
</table>
<div class="footer">{{ __('payroll.pdf.cash.footer') }}</div>
@endsection
