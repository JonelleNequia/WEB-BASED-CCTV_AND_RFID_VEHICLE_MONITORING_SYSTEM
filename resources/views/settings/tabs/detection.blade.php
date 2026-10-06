{{-- B3 (Settings › Advanced › Detection): live view, detection speed, vehicle type and counting (moved from Cameras). --}}
<section class="panel">
    <div class="panel-header panel-header-modern">
        <div>
            <h2 class="panel-title">Detection</h2>
            <p class="field-help">How fast and how strictly vehicles are detected. Change one thing at a time and check System status.</p>
        </div>
        @include('settings.partials.restore-defaults', ['section' => 'detection'])
    </div>
    <form method="POST" action="{{ route('settings.update') }}" class="stack-form">
        @csrf
        @method('PUT')
        <input type="hidden" name="section" value="detection">

            {{-- Live-latency work: tuning shared by both cameras. --}}
            <section class="subpanel">
                <div class="panel-title-row">
                    <h4>Live view performance</h4>
                    <p class="field-help">Lower values mean less delay and CPU. Measurements are in Settings › System Status.</p>
                </div>
                <div class="form-grid">
                    <div class="field">
                        <label for="perf_stream_fps">Live view FPS</label>
                        <input id="perf_stream_fps" type="number" name="perf_stream_fps" min="1" max="30" step="1" value="{{ old('perf_stream_fps', $settings['perf_stream_fps']) }}">
                    </div>
                    <div class="field">
                        <label for="perf_stream_width">Live view width (px)</label>
                        <input id="perf_stream_width" type="number" name="perf_stream_width" min="320" max="1920" step="16" value="{{ old('perf_stream_width', $settings['perf_stream_width']) }}">
                    </div>
                    <div class="field">
                        <label for="perf_jpeg_quality">Live view JPEG quality</label>
                        <input id="perf_jpeg_quality" type="number" name="perf_jpeg_quality" min="30" max="95" value="{{ old('perf_jpeg_quality', $settings['perf_jpeg_quality']) }}">
                    </div>
                    <div class="field">
                        <label for="perf_detection_fps">Detection runs per second</label>
                        <input id="perf_detection_fps" type="number" name="perf_detection_fps" min="1" max="25" step="1" value="{{ old('perf_detection_fps', $settings['perf_detection_fps']) }}">
                    </div>
                    <div class="field">
                        <label for="perf_yolo_imgsz">Detection input size</label>
                        <select id="perf_yolo_imgsz" name="perf_yolo_imgsz">
                            @foreach ([320, 384, 416, 480, 512, 640] as $size)
                                <option value="{{ $size }}" @selected((string) old('perf_yolo_imgsz', $settings['perf_yolo_imgsz']) === (string) $size)>{{ $size }}{{ $size === 480 ? ' (recommended)' : '' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="perf_yolo_device">Detection runs on</label>
                        <select id="perf_yolo_device" name="perf_yolo_device">
                            @foreach (['auto' => 'Automatic (GPU if available)', 'cpu' => 'CPU', 'mps' => 'Apple GPU (Mac)', 'cuda:0' => 'NVIDIA GPU'] as $value => $text)
                                <option value="{{ $value }}" @selected(old('perf_yolo_device', $settings['perf_yolo_device']) === $value)>{{ $text }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field span-full">
                        <label class="checkbox-row">
                            <input type="hidden" name="perf_roi_crop" value="0">
                            <input type="checkbox" name="perf_roi_crop" value="1" @checked(old('perf_roi_crop', $settings['perf_roi_crop']) === '1')>
                            Detect only inside the calibrated zone (faster, sharper for small zones)
                        </label>
                        <label class="checkbox-row">
                            <input type="hidden" name="perf_hires_on_trigger" value="0">
                            <input type="checkbox" name="perf_hires_on_trigger" value="1" @checked(old('perf_hires_on_trigger', $settings['perf_hires_on_trigger']) === '1')>
                            Use full-resolution frames for alert snapshots and plate reading
                        </label>
                    </div>
                </div>
            </section>

            {{-- A2 (detection): how the vehicle type is decided (moves to Advanced in the new Settings). --}}
            <section class="subpanel">
                <div class="panel-title-row">
                    <h4>Vehicle type</h4>
                    <p class="field-help">Types: Car (sedan, SUV, AUV, pickup, van), Motorcycle (motorcycle, e-bike, tricycle), Truck/Bus (truck, bus, jeepney). Every frame of a vehicle votes. The camera sees vehicles from behind: Truck/Bus needs a back that is both tall in the zone and about as tall as it is wide; anything wider (SUV, AUV, pickup, van) is a Car.</p>
                </div>
                <div class="form-grid">
                    <div class="field">
                        <label for="perf_type_truck_min_height">Truck/Bus: back at least this tall (% of the zone's height)</label>
                        <input id="perf_type_truck_min_height" type="number" name="perf_type_truck_min_height" min="20" max="95" step="1" value="{{ old('perf_type_truck_min_height', $settings['perf_type_truck_min_height']) }}">
                        <span class="field-help">Lower it if real trucks are saved as Car.</span>
                    </div>
                    <div class="field">
                        <label for="perf_type_car_min_aspect">Truck/Bus: back narrower than (width ÷ height)</label>
                        <input id="perf_type_car_min_aspect" type="number" name="perf_type_car_min_aspect" min="0.8" max="2.5" step="0.05" value="{{ old('perf_type_car_min_aspect', $settings['perf_type_car_min_aspect']) }}">
                        <span class="field-help">Trucks, buses and jeepneys are about 0.7–0.9; pickups measured 1.2 and up. Raise it if real trucks are saved as Car; lower it if pickups or AUVs are saved as Truck/Bus.</span>
                    </div>
                    <div class="field">
                        <label for="perf_type_model">Second check model</label>
                        <select id="perf_type_model" name="perf_type_model">
                            <option value="yolov8n.pt" @selected(old('perf_type_model', $settings['perf_type_model']) === 'yolov8n.pt')>Fast (yolov8n)</option>
                            <option value="yolov8s.pt" @selected(old('perf_type_model', $settings['perf_type_model']) === 'yolov8s.pt')>More accurate (yolov8s, recommended)</option>
                        </select>
                    </div>
                    <div class="field span-full">
                        <label class="checkbox-row">
                            <input type="hidden" name="perf_type_second_pass" value="0">
                            <input type="checkbox" name="perf_type_second_pass" value="1" @checked(old('perf_type_second_pass', $settings['perf_type_second_pass']) === '1')>
                            Check the type again on the sharpest full-size picture of each vehicle
                        </label>
                    </div>
                </div>
            </section>

            {{-- A3 (detection): when a vehicle counts (moves to Advanced in the new Settings). --}}
            <section class="subpanel">
                <div class="panel-title-row">
                    <h4>Counting</h4>
                    <p class="field-help">A vehicle counts once, when it is clearly past the trigger line. Stopping, rocking or backing up on the line and parked vehicles are not counted.</p>
                </div>
                <div class="form-grid">
                    <div class="field">
                        <label for="perf_cross_margin">Past the line by (% of the zone's height)</label>
                        <input id="perf_cross_margin" type="number" name="perf_cross_margin" min="0" max="30" step="1" value="{{ old('perf_cross_margin', $settings['perf_cross_margin']) }}">
                        <span class="field-help">Raise it if a vehicle stopping on the line is counted twice.</span>
                    </div>
                    <div class="field">
                        <label for="perf_cross_min_points">Seen at least (times)</label>
                        <input id="perf_cross_min_points" type="number" name="perf_cross_min_points" min="1" max="10" step="1" value="{{ old('perf_cross_min_points', $settings['perf_cross_min_points']) }}">
                        <span class="field-help">Lower it if fast vehicles are missed.</span>
                    </div>
                    <div class="field">
                        <label for="perf_cross_min_move">Moved at least (% of the zone's height)</label>
                        <input id="perf_cross_min_move" type="number" name="perf_cross_min_move" min="0" max="60" step="1" value="{{ old('perf_cross_min_move', $settings['perf_cross_min_move']) }}">
                        <span class="field-help">Keeps parked vehicles from being counted.</span>
                    </div>
                </div>
            </section>

        <div class="button-row button-row-end">
            <button type="submit" class="button button-primary">Save</button>
        </div>
    </form>
</section>
