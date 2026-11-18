@if ($loginsToCreate->isNotEmpty())
    <section class="pl-todo">
        <div class="pl-todo-head">
            <span class="pl-todo-ico"><i data-lucide="key-round"></i></span>
            <div>
                <strong>{{ $loginsToCreate->count() === 1 ? __('field.login_todo_one') : __('field.login_todo_many', ['count' => $loginsToCreate->count()]) }}</strong>
                <span>{{ __('field.login_todo_hint') }}</span>
            </div>
        </div>
        <div class="pl-todo-list">
            @foreach ($loginsToCreate as $todo)
                <a class="pl-todo-item" href="{{ route('field.partners.login', $todo) }}">
                    <span class="pl-todo-name">{{ $todo->shop?->name ?? $todo->business_name }}<small>{{ collect([$todo->shop?->code, $todo->reviewed_at?->diffForHumans()])->filter()->implode(' · ') }}</small></span>
                    <span class="f-btn f-btn-primary f-btn-sm"><i data-lucide="user-plus"></i> {{ __('field.create_login') }}</span>
                </a>
            @endforeach
        </div>
    </section>
@endif
