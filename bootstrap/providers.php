<?php

use App\Domain\Attendance\AttendanceServiceProvider;
use App\Domain\Leave\LeaveServiceProvider;
use App\Domain\Payroll\PayrollServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\DomainServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    AppServiceProvider::class,
    DomainServiceProvider::class,
    FortifyServiceProvider::class,
    LeaveServiceProvider::class,
    AttendanceServiceProvider::class, // after Leave: binds the real LeaveAttendancePort
    PayrollServiceProvider::class, // after Leave and Attendance: binds the real Payroll ports
];
