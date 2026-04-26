@if (session('status'))
    <div class="alert alert-success">
        <div class="alert-icon">i</div>
        <div class="alert-copy">
            <strong>Action completed</strong>
            <p>{{ session('status') }}</p>
        </div>
    </div>
@endif

@if ($errors->any())
    <div class="alert alert-danger">
        <div class="alert-icon">!</div>
        <div class="alert-copy">
            <strong>Please review the form fields below.</strong>
            <ul class="error-list">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
