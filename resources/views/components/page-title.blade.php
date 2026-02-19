@props(['title', 'breadcrumbs' => []])

<div class="bg-light py-4 mb-5">
    <div class="container">
        <h1 class="h2 mb-2">{{ $title }}</h1>
        
        @if(count($breadcrumbs))
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item">
                        <a href="{{ route('home') }}" class="text-decoration-none">Ana Sayfa</a>
                    </li>
                    @foreach($breadcrumbs as $breadcrumb)
                        <li class="breadcrumb-item {{ !$loop->last ? 'active' : '' }}">
                            @if(isset($breadcrumb['url']) && !$loop->last)
                                <a href="{{ $breadcrumb['url'] }}" class="text-decoration-none">{{ $breadcrumb['title'] }}</a>
                            @else
                                {{ $breadcrumb['title'] }}
                            @endif
                        </li>
                    @endforeach
                </ol>
            </nav>
        @endif
    </div>
</div> 