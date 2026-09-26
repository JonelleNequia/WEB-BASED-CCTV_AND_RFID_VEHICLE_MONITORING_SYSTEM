{{-- UI Phase 2: one form per Settings tab, each with its own Save button. --}}
<section class="panel">
    <form method="POST" action="{{ route('settings.update') }}" class="stack-form" id="settings-form">
        @csrf
        @method('PUT')
        <input type="hidden" name="section" value="cameras">

            <section class="subpanel">
                <div class="panel-title-row">
                    <h4>Camera Sources</h4>
                </div>

                <div class="camera-grid">
                    @foreach (['entrance' => 'Entrance Camera', 'exit' => 'Exit Camera'] as $role => $label)
                        @php($camera = $cameraConfigs[$role])
                        <article class="camera-card">
                            <div class="camera-card-head">
                                <div>
                            <h4>{{ $label }}</h4>
                            <p>{{ ucfirst($role) }} monitoring feed</p>
                                </div>
                            </div>

                            <div class="form-grid">
                                <div class="field">
                                    <label for="{{ $role }}_camera_name">Camera Label</label>
                                    <input id="{{ $role }}_camera_name" type="text" name="camera_configs[{{ $role }}][camera_name]" value="{{ old("camera_configs.$role.camera_name", $camera['camera_name']) }}" required>
                                    @error("camera_configs.$role.camera_name")
                                        <span class="field-error">{{ $message }}</span>
                                    @enderror
                                </div>

                                <div class="field">
                                    <label for="{{ $role }}_source_type">Source Type</label>
                                    <select id="{{ $role }}_source_type" name="camera_configs[{{ $role }}][source_type]" required>
                                        <option value="webcam" @selected(old("camera_configs.$role.source_type", $camera['source_type']) === 'webcam')>Webcam</option>
                                        <option value="rtsp" @selected(old("camera_configs.$role.source_type", $camera['source_type']) === 'rtsp')>RTSP</option>
                                        <option value="url" @selected(old("camera_configs.$role.source_type", $camera['source_type']) === 'url')>URL</option>
                                    </select>
                                    @error("camera_configs.$role.source_type")
                                        <span class="field-error">{{ $message }}</span>
                                    @enderror
                                </div>

                                <div class="field">
                                    <label for="{{ $role }}_source_value">Source Value</label>
                                    <input
                                        id="{{ $role }}_source_value"
                                        type="text"
                                        name="camera_configs[{{ $role }}][source_value]"
                                        value="{{ old("camera_configs.$role.source_value", $camera['source_value']) }}"
                                        placeholder="rtsp://192.168.1.50:554/stream1"
                                        required
                                    >
                                    <span class="field-help">Webcam uses a number like 0. RTSP must use the full rtsp:// camera URL.</span>
                                    @error("camera_configs.$role.source_value")
                                        <span class="field-error">{{ $message }}</span>
                                    @enderror
                                </div>

                                <div class="field">
                                    <label for="{{ $role }}_source_username">Username</label>
                                    <input id="{{ $role }}_source_username" type="text" name="camera_configs[{{ $role }}][source_username]" value="{{ old("camera_configs.$role.source_username", $camera['source_username']) }}">
                                    @error("camera_configs.$role.source_username")
                                        <span class="field-error">{{ $message }}</span>
                                    @enderror
                                </div>

                                <div class="field">
                                    <label for="{{ $role }}_source_password">Password</label>
                                    <input id="{{ $role }}_source_password" type="password" name="camera_configs[{{ $role }}][source_password]" value="{{ old("camera_configs.$role.source_password", $camera['source_password']) }}">
                                    @error("camera_configs.$role.source_password")
                                        <span class="field-error">{{ $message }}</span>
                                    @enderror
                                </div>

                                <div class="field span-full">
                                    <label>Saved Browser Device</label>
                                    <input type="text" value="{{ $camera['browser_label'] ?: 'No saved browser device yet.' }}" readonly>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>

        <div class="button-row button-row-end">
            <button type="submit" class="button button-primary">Save Cameras</button>
        </div>
    </form>
</section>
