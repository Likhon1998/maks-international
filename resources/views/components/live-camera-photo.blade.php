@props([
    'name' => 'photo',
    'required' => false,
    'title' => 'Take a live photo',
    'hint' => 'Camera only — gallery photos are blocked for reality check.',
    'oldPreview' => null,
])

@php
    $inputId = 'cam-'.Str::slug($name).'-'.uniqid();
@endphp

<div
    {{ $attributes->class(['f-cam', $required ? 'is-required' : '']) }}
    x-data="liveCameraPhoto({
        required: {{ $required ? 'true' : 'false' }},
        inputName: @js($name),
        inputId: @js($inputId),
    })"
    x-cloak
>
    <input type="hidden" name="photo_captured" :value="hasPhoto ? '1' : ''">
    <input
        type="file"
        :id="inputId"
        :name="inputName"
        accept="image/jpeg"
        class="f-cam-file"
        tabindex="-1"
        aria-hidden="true"
        @change="onFileFallback($event)"
    >

    <div class="f-cam-idle" x-show="!open && !preview">
        <button type="button" class="f-cam-launch" @click="openCamera()">
            <i data-lucide="camera"></i>
            <b>{{ $title }}</b>
            <small>{{ $hint }}</small>
        </button>
        @if (! $required)
            <p class="f-muted f-small" style="margin:8px 0 0;text-align:center">Optional — skip if not needed.</p>
        @endif
    </div>

    <div class="f-cam-stage" x-show="open" x-cloak>
        <video x-ref="video" class="f-cam-video" playsinline muted autoplay></video>
        <canvas x-ref="canvas" class="f-cam-canvas" hidden></canvas>
        <div class="f-cam-actions">
            <button type="button" class="f-btn f-btn-ghost f-btn-sm" @click="closeCamera()">Cancel</button>
            <button type="button" class="f-btn f-btn-primary f-btn-sm" @click="snap()" :disabled="!ready">
                <i data-lucide="aperture"></i> Capture
            </button>
        </div>
        <p class="f-cam-err" x-show="error" x-text="error"></p>
    </div>

    <div class="f-cam-preview" x-show="!open && preview" x-cloak>
        <img :src="preview" alt="Captured photo">
        <div class="f-cam-actions">
            <button type="button" class="f-btn f-btn-ghost f-btn-sm" @click="retake()">
                <i data-lucide="refresh-cw"></i> Retake live photo
            </button>
        </div>
        <p class="f-muted f-small" style="margin:6px 0 0;text-align:center">Live camera capture only</p>
    </div>
</div>

@once
    @push('scripts')
        <script>
            window.liveCameraPhoto = function (cfg) {
                return {
                    required: !!cfg.required,
                    inputName: cfg.inputName || 'photo',
                    inputId: cfg.inputId,
                    open: false,
                    ready: false,
                    preview: '',
                    hasPhoto: false,
                    fromCapture: false,
                    error: '',
                    stream: null,
                    init() {
                        this.$el.closest('form')?.addEventListener('submit', (e) => {
                            if (this.required && !this.hasPhoto) {
                                e.preventDefault();
                                this.error = 'Take a live photo with the camera first. Gallery photos are not allowed.';
                                this.openCamera();
                            }
                        });
                    },
                    async openCamera() {
                        this.error = '';
                        this.open = true;
                        this.ready = false;
                        this.$nextTick(() => window.lucide && lucide.createIcons());
                        try {
                            if (!navigator.mediaDevices?.getUserMedia) {
                                throw new Error('Camera API unavailable on this device/browser.');
                            }
                            this.stream = await navigator.mediaDevices.getUserMedia({
                                audio: false,
                                video: {
                                    facingMode: { ideal: 'environment' },
                                    width: { ideal: 1280 },
                                    height: { ideal: 720 },
                                },
                            });
                            const video = this.$refs.video;
                            video.srcObject = this.stream;
                            await video.play();
                            this.ready = true;
                        } catch (err) {
                            this.error = 'Allow camera access and try again. Gallery upload is disabled.';
                            this.open = false;
                            this.stopStream();
                        }
                    },
                    stopStream() {
                        (this.stream?.getTracks?.() || []).forEach((t) => t.stop());
                        this.stream = null;
                        if (this.$refs.video) this.$refs.video.srcObject = null;
                        this.ready = false;
                    },
                    closeCamera() {
                        this.open = false;
                        this.stopStream();
                        this.error = '';
                    },
                    async snap() {
                        const video = this.$refs.video;
                        const canvas = this.$refs.canvas;
                        if (!video || !canvas || !this.ready) return;

                        const w = video.videoWidth || 1280;
                        const h = video.videoHeight || 720;
                        canvas.width = w;
                        canvas.height = h;
                        const ctx = canvas.getContext('2d');
                        ctx.drawImage(video, 0, 0, w, h);

                        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.9));
                        if (!blob) {
                            this.error = 'Could not capture photo. Try again.';
                            return;
                        }

                        const file = new File([blob], 'live-capture-' + Date.now() + '.jpg', {
                            type: 'image/jpeg',
                            lastModified: Date.now(),
                        });
                        this.assignFile(file);
                        if (this.preview) URL.revokeObjectURL(this.preview);
                        this.preview = URL.createObjectURL(blob);
                        this.hasPhoto = true;
                        this.closeCamera();
                        this.$nextTick(() => window.lucide && lucide.createIcons());
                    },
                    assignFile(file) {
                        const input = document.getElementById(this.inputId);
                        if (!input) return;
                        const dt = new DataTransfer();
                        dt.items.add(file);
                        this.fromCapture = true;
                        input.files = dt.files;
                    },
                    clearFile() {
                        const input = document.getElementById(this.inputId);
                        if (!input) return;
                        input.value = '';
                    },
                    retake() {
                        if (this.preview) URL.revokeObjectURL(this.preview);
                        this.preview = '';
                        this.hasPhoto = false;
                        this.fromCapture = false;
                        this.clearFile();
                        this.openCamera();
                    },
                    // Allow only files placed by snap(); reject gallery/picker selection.
                    onFileFallback(event) {
                        if (this.fromCapture) {
                            this.fromCapture = false;
                            return;
                        }
                        event.target.value = '';
                        this.error = 'Gallery photos are not allowed. Use live camera capture.';
                        this.hasPhoto = false;
                        this.preview = '';
                    },
                };
            };
        </script>
    @endpush
@endonce
