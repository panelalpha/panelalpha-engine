@extends('vault.layout', ['title' => 'Connect your repository'])

@section('card')
    <main class="card">
        <h1 class="title">
            <span class="tile"><span style="width:24px;height:24px;background-image:url('/vault/icons/git.svg')"></span></span>
            Connect your repository
        </h1>

        <p class="lede">To deploy a private repository, Engine needs a read-only token to clone it.</p>

        @include('vault.partials.purpose', ['purpose' => $entry->purpose])

        @include('vault.partials.git-token')
    </main>
@endsection
