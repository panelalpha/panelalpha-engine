{{--
    Help and the generic sentence say the same thing, so only one renders:
    `resources/vault/<type>.md` when it exists, `default.md` otherwise.
--}}
@extends('vault.layout', ['title' => 'Paste secret'])

@section('card')
    <main class="card">
        <h1 class="title">
            <span class="tile"><span style="width:24px;height:24px;background-image:url('/vault/icons/git.svg')"></span></span>
            Paste your secret
        </h1>

        @if ($helpHtml !== '')
            <div class="steps">{!! $helpHtml !!}</div>
        @else
            <p class="lede">You were sent here by an assistant that needs this value to finish what you asked for. Paste it below.</p>
        @endif

        @include('vault.partials.purpose', ['purpose' => $entry->purpose])

        @include('vault.partials.paste', [
            'label' => 'Secret',
            'placeholder' => 'paste the value here',
            'note' => $note,
        ])
    </main>
@endsection
