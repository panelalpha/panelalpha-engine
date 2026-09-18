@extends('vault.layout', ['title' => 'Connect Cloudflare'])

@section('card')
    <main class="card">
        <h1 class="title">
            <span class="tile"><span style="width:26px;height:26px;background-image:url('/vault/icons/cloudflare.svg')"></span></span>
            Connect Cloudflare
        </h1>

        <p class="lede">To connect Cloudflare, Engine needs an API token that can manage tunnels and DNS on your domain.</p>

        @include('vault.partials.purpose', ['purpose' => $entry->purpose])

        @include('vault.steps.cloudflare')

        @include('vault.partials.paste', [
            'label' => 'Paste the token here',
            'placeholder' => 'cf_…',
            'note' => $note,
        ])
    </main>
@endsection
