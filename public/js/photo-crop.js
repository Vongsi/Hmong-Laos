/*
 * Profile photo cropping (My account → Your details): pick a photo, move and zoom it in a square, and that square
 * goes to the site as a 512×512 JPEG (App\Livewire\Account::savePhoto, App\Support\ProfilePhoto).
 * Drag to move; zoom with the slider, two fingers or the mouse wheel; the arrow keys and + / − work too.
 * Use: <div x-data="photoCrop($wire)"> with x-ref="dialog" (a <dialog>) and x-ref="stage" (a square <canvas>).
 * Must load before Livewire starts Alpine (account.antlers.html).
 */
(function () {
    const STAGE = 300;  // the square, in CSS pixels at full size
    const OUT = 512;    // the photo sent to the site
    const MAX_ZOOM = 4;

    /** The picture, upright (phone photos store their rotation separately), as something canvas can draw. */
    async function open(file) {
        if (window.createImageBitmap) {
            try {
                return await createImageBitmap(file, { imageOrientation: 'from-image' });
            } catch (e) { /* fall back to an <img> below */ }
        }
        const img = new Image();
        img.src = URL.createObjectURL(file);
        await img.decode();
        return img;
    }

    window.photoCrop = function (wire) {
        let picture = null, width = 0, height = 0;
        let base = 1, scale = 1, x = 0, y = 0;  // the picture's size and top-left corner in the square
        const pointers = new Map();
        let pinch = null;

        return {
            zoom: 1,
            busy: false,
            failed: false,

            async pick(event) {
                const file = event.target.files && event.target.files[0];
                event.target.value = '';  // choosing the same file again still opens the window
                if (!file) return;
                this.failed = false;
                try {
                    picture = await open(file);
                } catch (e) {
                    this.failed = true;
                    return;
                }
                width = picture.width || picture.naturalWidth;
                height = picture.height || picture.naturalHeight;
                base = Math.max(STAGE / width, STAGE / height);  // the whole square filled
                this.zoom = 1;
                scale = base;
                x = (STAGE - width * scale) / 2;
                y = (STAGE - height * scale) / 2;
                this.$refs.dialog.showModal();
                this.draw();
            },

            draw() {
                const canvas = this.$refs.stage;
                const context = canvas.getContext('2d');
                const ratio = canvas.width / STAGE;
                context.setTransform(ratio, 0, 0, ratio, 0, 0);
                context.fillStyle = '#fff';
                context.fillRect(0, 0, STAGE, STAGE);
                if (picture) context.drawImage(picture, x, y, width * scale, height * scale);
                // Darker outside the circle members will see.
                context.fillStyle = 'rgba(16, 19, 42, .5)';
                context.beginPath();
                context.rect(0, 0, STAGE, STAGE);
                context.arc(STAGE / 2, STAGE / 2, STAGE / 2 - 1, 0, Math.PI * 2, true);
                context.fill('evenodd');
            },

            /** Keeps the square covered by the picture. */
            keepInside() {
                x = Math.min(0, Math.max(STAGE - width * scale, x));
                y = Math.min(0, Math.max(STAGE - height * scale, y));
            },

            /** Zooms around a point of the square (its middle by default). */
            setZoom(value, cx = STAGE / 2, cy = STAGE / 2) {
                const px = (cx - x) / scale, py = (cy - y) / scale;
                this.zoom = Math.min(MAX_ZOOM, Math.max(1, Number(value)));
                scale = base * this.zoom;
                x = cx - px * scale;
                y = cy - py * scale;
                this.keepInside();
                this.draw();
            },

            move(dx, dy) {
                x += dx;
                y += dy;
                this.keepInside();
                this.draw();
            },

            /** A point of the page in the square's own units. */
            local(event) {
                const box = this.$refs.stage.getBoundingClientRect();
                const unit = STAGE / box.width;
                return { x: (event.clientX - box.left) * unit, y: (event.clientY - box.top) * unit, unit };
            },

            down(event) {
                this.$refs.stage.setPointerCapture(event.pointerId);
                pointers.set(event.pointerId, this.local(event));
                if (pointers.size === 2) {
                    const [a, b] = [...pointers.values()];
                    pinch = { distance: Math.hypot(a.x - b.x, a.y - b.y), zoom: this.zoom };
                }
            },

            drag(event) {
                if (!pointers.has(event.pointerId)) return;
                const before = pointers.get(event.pointerId);
                const now = this.local(event);
                pointers.set(event.pointerId, now);
                if (pointers.size === 1) {
                    this.move(now.x - before.x, now.y - before.y);
                } else if (pinch) {
                    const [a, b] = [...pointers.values()];
                    this.setZoom(pinch.zoom * Math.hypot(a.x - b.x, a.y - b.y) / pinch.distance, (a.x + b.x) / 2, (a.y + b.y) / 2);
                }
            },

            up(event) {
                pointers.delete(event.pointerId);
                if (pointers.size < 2) pinch = null;
            },

            wheel(event) {
                const point = this.local(event);
                this.setZoom(this.zoom * (event.deltaY < 0 ? 1.1 : 1 / 1.1), point.x, point.y);
            },

            key(event) {
                const steps = { ArrowLeft: [10, 0], ArrowRight: [-10, 0], ArrowUp: [0, 10], ArrowDown: [0, -10] };
                if (steps[event.key]) this.move(...steps[event.key]);
                else if (event.key === '+' || event.key === '=') this.setZoom(this.zoom * 1.1);
                else if (event.key === '-') this.setZoom(this.zoom / 1.1);
                else return;
                event.preventDefault();
            },

            async save() {
                if (!picture || this.busy) return;
                this.busy = true;
                this.failed = false;
                const out = document.createElement('canvas');
                out.width = out.height = OUT;
                const context = out.getContext('2d');
                context.fillStyle = '#fff';
                context.fillRect(0, 0, OUT, OUT);
                context.drawImage(picture, -x / scale, -y / scale, STAGE / scale, STAGE / scale, 0, 0, OUT, OUT);
                const blob = await new Promise((resolve) => out.toBlob(resolve, 'image/jpeg', 0.9));
                if (!blob) {
                    this.busy = false;
                    this.failed = true;
                    return;
                }
                wire.upload('photo', new File([blob], 'photo.jpg', { type: 'image/jpeg' }),
                    () => wire.savePhoto().then(() => { this.busy = false; this.close(); }),
                    () => { this.busy = false; this.failed = true; });
            },

            close() {
                this.$refs.dialog.close();
                picture?.close?.();
                picture = null;
                pointers.clear();
                pinch = null;
            },
        };
    };
})();
