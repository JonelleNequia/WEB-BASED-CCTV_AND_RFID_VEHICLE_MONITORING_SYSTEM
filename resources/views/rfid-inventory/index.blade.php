@extends('layouts.app')

@section('title', 'RFID Inventory | PHILCST Vehicle Access Monitoring')
@section('page-title', 'RFID Inventory')
@section('page-description', 'Bulk-enroll RFID cards into the offline available tag pool before vehicle assignment.')

@section('content')
    <section class="hero-panel hero-panel-compact">
        <div class="hero-panel-copy">
            <span class="panel-kicker">Offline Tag Pool</span>
            <h3>RFID tag inventory enrollment</h3>
            <div class="inline-status-list">
                <span class="chip chip-brand">Available: {{ $tagStats['available'] ?? 0 }}</span>
                <span class="chip chip-soft">Assigned: {{ $tagStats['assigned'] ?? 0 }}</span>
                <span class="chip chip-soft">Inactive: {{ $tagStats['inactive'] ?? 0 }}</span>
            </div>
        </div>

        <div class="hero-panel-actions">
            <a href="{{ route('vehicle-registry.index') }}" class="button button-primary">Vehicle Registry</a>
            <a href="{{ route('rfid-scans.index') }}" class="button button-secondary">RFID Desk</a>
        </div>
    </section>

    <div class="page-grid two-column">
        <section class="panel">
            <div class="panel-header">
                <div>
                    <div class="panel-title-row">
                        <h3>Scan Tags</h3>
                        @include('layouts.partials.help', [
                            'label' => 'Explain RFID inventory scanner',
                            'text' => 'Use this page to scan unassigned RFID cards first. The vehicle registry can only choose tags from this available pool.',
                        ])
                    </div>
                </div>
            </div>

            <form method="POST" action="{{ route('rfid-inventory.store') }}" class="stack-form" data-rfid-inventory-form>
                @csrf

                <div class="field">
                    <label for="uid">RFID UID</label>
                    <input id="uid" type="text" name="uid" value="{{ old('uid') }}" autocomplete="off" placeholder="Scan RFID card" required autofocus data-rfid-inventory-input>
                    @error('uid')
                        <span class="field-error">{{ $message }}</span>
                    @enderror
                </div>

                <div class="button-row">
                    <button type="submit" class="button button-primary">Add Tag</button>
                    <a href="{{ route('vehicle-registry.index') }}" class="button button-secondary">Assign Tags</a>
                </div>
            </form>

            <div class="mini-note">
                <strong>Inventory scans do not create ENTRY/EXIT logs.</strong>
                <p>Kapag assigned na ang tag, scan it from RFID Desk, Entrance Station, or Exit Station para ma-record sa vehicle activity.</p>
            </div>

            <div class="mini-note" data-rfid-inventory-result hidden></div>
        </section>

        <section class="panel">
            <div class="panel-header">
                <div>
                    <div class="panel-title-row">
                        <h3>Available Tags</h3>
                        @include('layouts.partials.help', [
                            'label' => 'Explain available tags',
                            'text' => 'Only tags with available status appear in the vehicle registration dropdown.',
                        ])
                    </div>
                </div>
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>UID</th>
                            <th>Status</th>
                            <th>Enrolled</th>
                        </tr>
                    </thead>
                    <tbody data-rfid-inventory-table>
                        @forelse ($availableTags as $tag)
                            <tr data-rfid-inventory-row="{{ $tag->uid }}">
                                <td><strong>{{ $tag->uid }}</strong></td>
                                <td><span class="badge badge-open">{{ ucfirst($tag->status) }}</span></td>
                                <td>{{ $tag->created_at?->format('M d, Y h:i A') ?? 'N/A' }}</td>
                            </tr>
                        @empty
                            <tr data-rfid-inventory-empty>
                                <td colspan="3" class="table-empty">No available RFID tags yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const form = document.querySelector('[data-rfid-inventory-form]');
            const input = document.querySelector('[data-rfid-inventory-input]');
            const result = document.querySelector('[data-rfid-inventory-result]');
            const table = document.querySelector('[data-rfid-inventory-table]');

            if (!form || !input || !result || !table) {
                return;
            }

            const focusInput = () => {
                input.focus();
                input.select();
            };

            const renderRows = (tags) => {
                table.innerHTML = '';

                if (!tags.length) {
                    table.innerHTML = '<tr data-rfid-inventory-empty><td colspan="3" class="table-empty">No available RFID tags yet.</td></tr>';
                    return;
                }

                tags.forEach((tag) => {
                    const row = document.createElement('tr');
                    row.dataset.rfidInventoryRow = tag.uid;

                    const uidCell = document.createElement('td');
                    const uidText = document.createElement('strong');
                    uidText.textContent = tag.uid;
                    uidCell.appendChild(uidText);

                    const statusCell = document.createElement('td');
                    const statusBadge = document.createElement('span');
                    statusBadge.className = 'badge badge-open';
                    statusBadge.textContent = tag.status.charAt(0).toUpperCase() + tag.status.slice(1);
                    statusCell.appendChild(statusBadge);

                    const createdCell = document.createElement('td');
                    createdCell.textContent = tag.created_at || 'N/A';

                    row.append(uidCell, statusCell, createdCell);
                    table.appendChild(row);
                });
            };

            focusInput();

            document.addEventListener('click', (event) => {
                const target = event.target;
                const isFormField = target instanceof HTMLElement
                    && ['INPUT', 'SELECT', 'TEXTAREA', 'BUTTON', 'A'].includes(target.tagName);

                if (!isFormField) {
                    focusInput();
                }
            });

            form.addEventListener('submit', async (event) => {
                event.preventDefault();

                const uid = input.value.trim();

                if (!uid) {
                    focusInput();
                    return;
                }

                result.hidden = false;
                result.textContent = 'Saving RFID tag...';

                try {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: new FormData(form),
                    });
                    const payload = await response.json();

                    result.textContent = payload.message || 'RFID tag scan processed.';

                    if (!response.ok && payload.tag?.status === 'assigned') {
                        const plate = payload.tag.vehicle_plate || 'registered vehicle';
                        result.textContent = `${payload.tag.uid} is already assigned to ${plate}. Use RFID Desk, Entrance Station, or Exit Station to record ENTRY/EXIT scans.`;
                    }

                    if (response.ok) {
                        input.value = '';
                        renderRows(payload.available_tags || []);
                    }
                } catch (error) {
                    result.textContent = 'RFID tag could not be saved.';
                } finally {
                    focusInput();
                }
            });
        });
    </script>
@endpush
