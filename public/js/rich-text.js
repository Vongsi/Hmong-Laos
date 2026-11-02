/*
 * Rich text boxes for members (Share page): a small toolbar over a TipTap editor
 * (public/js/vendor/tiptap-*.min.js) whose HTML is kept in a Livewire property.
 * Use: <div wire:ignore x-data="richText($wire, 'body', 'textPhoto', 'storeTextPhoto')" data-label="…" data-link-prompt="…">
 *        …toolbar buttons calling toggle('bold') / link() / pickPhotos()… <div x-ref="area"></div></div>
 * Photos: chosen with the Photo button, pasted or dropped, shrunk in the browser, uploaded to the Livewire
 * property `photoProperty`, then `storeMethod` saves them and returns their address.
 * The server keeps only what this toolbar offers (App\Support\RichText), whatever the browser sends.
 */
(function () {
    const PHOTO_SIZE = 1600; // longest side, in pixels
    const PHOTO_TYPES = /^image\/(jpeg|png|webp)$/;

    /** A smaller JPEG of a photo, made in the browser (phone photos are often 3-8 MB); the original if that fails. */
    async function shrink(file) {
        if (!PHOTO_TYPES.test(file.type) || !window.createImageBitmap) return file;
        try {
            const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
            const scale = Math.min(1, PHOTO_SIZE / Math.max(bitmap.width, bitmap.height));
            if (scale === 1 && file.size < 1500000) {
                bitmap.close?.();
                return file;
            }
            const canvas = document.createElement('canvas');
            canvas.width = Math.round(bitmap.width * scale);
            canvas.height = Math.round(bitmap.height * scale);
            const context = canvas.getContext('2d');
            context.fillStyle = '#fff'; // transparent PNGs get a white background, not black
            context.fillRect(0, 0, canvas.width, canvas.height);
            context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
            bitmap.close?.();
            const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.85));
            if (!blob || blob.size >= file.size) return file;
            return new File([blob], (file.name || 'photo').replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' });
        } catch (e) {
            return file;
        }
    }

    window.richText = function (wire, property, photoProperty, storeMethod) {
        let editor = null;
        let NodeSel = null; // ProseMirror NodeSelection, from the editor bundle

        return {
            active: {},
            // Nothing written yet: the page shows example text over the editor.
            empty: !wire.get(property),
            ready: false,
            uploading: 0,
            failed: false,
            // The selected photo's size and position, for the photo menu.
            photo: { size: null, align: null },

            init() {
                // The editor script loads with `defer`; wait for it if Alpine got here first.
                if (window.Tiptap) {
                    this.start();
                } else {
                    window.addEventListener('load', () => this.start(), { once: true });
                }
            },

            start() {
                if (editor || !window.Tiptap || !this.$refs.area) return;
                const { Editor, StarterKit, Image, BubbleMenu, NodeSelection } = window.Tiptap;
                const photoPrefix = this.$root.dataset.photoPrefix || '/assets/';
                // Only photos stored on this site; images pasted from other websites are left out.
                const ownPhoto = (src) => {
                    try {
                        const url = new URL(src, location.href);
                        return url.origin === location.origin && url.pathname.startsWith(photoPrefix);
                    } catch (e) {
                        return false;
                    }
                };
                // Size (small, medium; none = full width) and position (left, right; none = centred), as in
                // app/Bard/PhotoLayout.php, which the server and the control panel understand too.
                const layout = (name, values) => ({
                    default: null,
                    parseHTML: (element) => (values.includes(element.getAttribute('data-' + name)) ? element.getAttribute('data-' + name) : null),
                    renderHTML: (attributes) => (values.includes(attributes[name]) ? { ['data-' + name]: attributes[name], class: 'photo-' + attributes[name] } : {}),
                });
                const Photo = Image.extend({
                    parseHTML() {
                        return [{ tag: 'img[src]', getAttrs: (element) => (ownPhoto(element.getAttribute('src')) ? {} : false) }];
                    },
                    addAttributes() {
                        return { ...this.parent?.(), size: layout('size', ['small', 'medium']), align: layout('align', ['left', 'right']) };
                    },
                }).configure({ inline: true, allowBase64: false, HTMLAttributes: { class: 'rte-photo' } });
                NodeSel = NodeSelection;
                const photoSelected = (state) => state.selection instanceof NodeSelection && state.selection.node.type.name === 'image';
                // The photo menu (size, position, move, remove) appears next to a photo when it is tapped.
                const photoMenu = this.$refs.photoMenu
                    ? [BubbleMenu.configure({
                        element: this.$refs.photoMenu,
                        pluginKey: 'photoMenu',
                        updateDelay: 0,
                        shouldShow: ({ editor: e, view, state }) => e.isEditable && photoSelected(state)
                            && (view.hasFocus() || this.$refs.photoMenu.contains(document.activeElement)),
                    })]
                    : [];

                editor = new Editor({
                    element: this.$refs.area,
                    extensions: [
                        StarterKit.configure({
                            heading: { levels: [2, 3] },
                            code: false,
                            codeBlock: false,
                            horizontalRule: false,
                            strike: false,
                            underline: false,
                            link: {
                                openOnClick: false,
                                autolink: true,
                                defaultProtocol: 'https',
                                protocols: ['mailto', 'tel'],
                                HTMLAttributes: { rel: 'nofollow ugc noopener', target: '_blank' },
                            },
                        }),
                        ...(photoProperty ? [Photo, ...photoMenu] : []),
                    ],
                    content: wire.get(property) || '',
                    editorProps: {
                        attributes: {
                            class: 'rte-area',
                            role: 'textbox',
                            'aria-multiline': 'true',
                            'aria-label': this.$root.dataset.label || '',
                        },
                        // Photos pasted or dropped from the phone or computer are uploaded like the Photo button.
                        handlePaste: (view, event) => this.takePhotos(event.clipboardData?.files),
                        handleDrop: (view, event, slice, moved) => !moved && this.takePhotos(event.dataTransfer?.files),
                    },
                    // Sent with the next action (Send), not on every key press.
                    onUpdate: () => {
                        wire.set(property, editor.isEmpty ? '' : editor.getHTML(), false);
                        this.refresh();
                    },
                    onSelectionUpdate: () => this.refresh(),
                });
                this.ready = true;
                this.refresh();
            },

            refresh() {
                if (!editor) return;
                this.empty = editor.isEmpty;
                this.active = {
                    bold: editor.isActive('bold'),
                    italic: editor.isActive('italic'),
                    heading: editor.isActive('heading', { level: 2 }),
                    subheading: editor.isActive('heading', { level: 3 }),
                    bulletList: editor.isActive('bulletList'),
                    orderedList: editor.isActive('orderedList'),
                    blockquote: editor.isActive('blockquote'),
                    link: editor.isActive('link'),
                };
                const selection = editor.state.selection;
                const node = selection instanceof NodeSel && selection.node.type.name === 'image' ? selection.node : null;
                this.photo = { size: node?.attrs.size || null, align: node?.attrs.align || null };
            },

            /** Changes the selected photo's size or position ({ size: 'small' | 'medium' | null } / { align: … }). */
            setPhoto(attributes) {
                if (!editor || !editor.isActive('image')) return;
                // Changing a photo replaces it; select it again so the menu stays for the next choice.
                const position = editor.state.selection.from;
                editor.chain().focus().updateAttributes('image', attributes).setNodeSelection(position).run();
                this.refresh();
            },

            /**
             * Moves the selected photo up (-1) or down (1): a photo on its own goes above the paragraph before it
             * or below the one after it; a photo inside text first gets its own place just above or below that text.
             */
            movePhoto(direction) {
                if (!editor) return;
                const { state, view } = editor;
                const selection = state.selection;
                if (!(selection instanceof NodeSel) || selection.node.type.name !== 'image') return;
                const doc = state.doc;
                const image = selection.node;
                const index = doc.resolve(selection.from).index(0);
                const block = doc.child(index);
                const alone = block.type.name === 'paragraph' && block.childCount === 1;
                // On its own the photo jumps over the next block; inside text it lands next to that text.
                const target = alone ? index + direction : index;
                if (target < 0 || target >= doc.childCount) return;
                const start = (i) => { let pos = 0; for (let k = 0; k < i; k++) pos += doc.child(k).nodeSize; return pos; };

                let tr = state.tr;
                if (alone) {
                    tr = tr.delete(start(index), start(index) + block.nodeSize);
                } else {
                    tr = tr.delete(selection.from, selection.from + image.nodeSize);
                }
                const at = tr.mapping.map(direction < 0 ? start(target) : start(target) + doc.child(target).nodeSize, direction < 0 ? -1 : 1);
                tr = tr.insert(at, state.schema.nodes.paragraph.create(null, image));
                tr = tr.setSelection(NodeSel.create(tr.doc, at + 1)).scrollIntoView();
                view.dispatch(tr);
                view.focus();
                this.refresh();
            },

            removePhoto() {
                if (!editor || !editor.isActive('image')) return;
                editor.chain().focus().deleteSelection().run();
                this.refresh();
            },

            toggle(what) {
                if (!editor) return;
                const chain = editor.chain().focus();
                ({
                    bold: () => chain.toggleBold(),
                    italic: () => chain.toggleItalic(),
                    heading: () => chain.toggleHeading({ level: 2 }),
                    subheading: () => chain.toggleHeading({ level: 3 }),
                    bulletList: () => chain.toggleBulletList(),
                    orderedList: () => chain.toggleOrderedList(),
                    blockquote: () => chain.toggleBlockquote(),
                })[what]().run();
                this.refresh();
            },

            link() {
                if (!editor) return;
                const current = editor.getAttributes('link').href || '';
                const answer = window.prompt(this.$root.dataset.linkPrompt || 'Link', current || 'https://');
                if (answer === null) return;
                const url = answer.trim();
                const chain = editor.chain().focus().extendMarkRange('link');
                if (url === '' || url === 'https://') {
                    chain.unsetLink().run();
                } else if (editor.state.selection.empty && !current) {
                    // Nothing selected: add the address itself as a link.
                    chain.insertContent({ type: 'text', text: url, marks: [{ type: 'link', attrs: { href: url } }] }).run();
                } else {
                    chain.setLink({ href: url }).run();
                }
                this.refresh();
            },

            pickPhotos() {
                this.$refs.photoInput?.click();
            },

            /** Starts uploading the image files among these; true if there were any (so the paste/drop is handled). */
            takePhotos(fileList) {
                const files = [...(fileList || [])].filter((file) => PHOTO_TYPES.test(file.type)).slice(0, 6);
                if (!files.length || !photoProperty) return false;
                this.addPhotos(files);
                return true;
            },

            async addPhotos(files) {
                if (!editor) return;
                this.failed = false;
                for (const original of files) {
                    this.uploading++;
                    try {
                        const file = await shrink(original);
                        await new Promise((resolve, reject) => wire.upload(photoProperty, file, resolve, reject));
                        const result = await wire.call(storeMethod);
                        if (result && result.src) {
                            // Each new photo gets its own paragraph (medium-sized, centred; the photo menu changes that),
                            // with an empty one after it to carry on writing.
                            editor.chain().focus().insertContent([
                                { type: 'paragraph', content: [{ type: 'image', attrs: { src: result.src, alt: '', size: 'medium' } }] },
                                { type: 'paragraph' },
                            ]).run();
                        } else {
                            this.failed = true;
                        }
                    } catch (e) {
                        this.failed = true;
                    } finally {
                        this.uploading--;
                    }
                }
                if (this.$refs.photoInput) this.$refs.photoInput.value = '';
            },

            destroy() {
                editor?.destroy();
                editor = null;
            },
        };
    };
})();
