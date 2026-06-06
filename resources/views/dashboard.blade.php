@extends('layouts.tenant')

@section('title', 'Dashboard')

@section('content')
    <h1>LF Dashboard</h1>

    <p>
        Welcome back, {{ Auth::user()->name }}
    </p>
@endsection