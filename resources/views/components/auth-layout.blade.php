@props(['title' => null])
@php($title = $title)
@include('layouts.auth', ['slot' => $slot, 'title' => $title])
