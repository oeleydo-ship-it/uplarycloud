@props(['title' => null, 'description' => null, 'page' => null])
@include('layouts.marketing', ['slot' => $slot, 'title' => $title, 'description' => $description, 'page' => $page])
