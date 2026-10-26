/*
 * Control panel additions (loaded by Statamic::script('app', 'cp.js') in AppServiceProvider; styles in
 * public/vendor/app/css/cp.css).
 *
 * Photos in Bard text can have a size (small, medium; none = full width) and a position (left, right;
 * none = centred). Members choose them in the Share editor (public/js/rich-text.js); the site renders them with
 * app/Bard/PhotoLayout.php. Here the control panel's Bard learns the same two attributes (so saving keeps them),
 * shows each photo at its size and position, and gets toolbar buttons to change them when a photo is selected.
 */
Statamic.booting(() => {
    const SIZES = ['small', 'medium'];
    const t = (text) => (typeof window.__ === 'function' ? window.__(text) : text);
    const ALIGNS = ['left', 'right'];

    const layout = (name, values) => ({
        default: null,
        parseHTML: (element) => {
            const image = element.matches && element.matches('img') ? element : element.querySelector && element.querySelector('img');
            const value = image ? image.getAttribute('data-' + name) : null;
            return values.includes(value) ? value : null;
        },
        renderHTML: (attributes) => (values.includes(attributes[name]) ? { ['data-' + name]: attributes[name] } : {}),
    });

    // Marks the photo's box in the editor so cp.css can size and place it like on the site.
    const mark = (dom, node) => {
        if (!dom || !dom.setAttribute) return;
        const size = SIZES.includes(node.attrs.size) ? node.attrs.size : null;
        const align = ALIGNS.includes(node.attrs.align) ? node.attrs.align : null;
        size ? dom.setAttribute('data-photo-size', size) : dom.removeAttribute('data-photo-size');
        align ? dom.setAttribute('data-photo-align', align) : dom.removeAttribute('data-photo-align');
    };

    // The Size / Position row under each photo. Buttons inside a node view are left to us by the editor.
    const layoutBar = (props) => {
        const element = document.createElement('div');
        element.className = 'photo-layout-bar';
        element.setAttribute('contenteditable', 'false');
        const group = (label, name, options) => {
            const wrap = document.createElement('div');
            wrap.className = 'photo-layout-group';
            wrap.setAttribute('role', 'group');
            wrap.setAttribute('aria-label', label);
            const title = document.createElement('span');
            title.className = 'photo-layout-label';
            title.textContent = label;
            wrap.appendChild(title);
            options.forEach(([value, text]) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.textContent = text;
                button.dataset.name = name;
                button.dataset.value = value || '';
                button.addEventListener('mousedown', (event) => event.preventDefault());
                button.addEventListener('click', (event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    const position = typeof props.getPos === 'function' ? props.getPos() : null;
                    if (typeof position !== 'number' || !props.editor.isEditable) return;
                    props.editor.chain().focus().setNodeSelection(position)
                        .updateAttributes('image', { [name]: value }).setNodeSelection(position).run();
                });
                wrap.appendChild(button);
            });
            return wrap;
        };
        element.appendChild(group(t('Size'), 'size', [['small', t('Small')], ['medium', t('Medium')], [null, t('Full width')]]));
        element.appendChild(group(t('Position'), 'align', [['left', t('Left')], [null, t('Centre')], ['right', t('Right')]]));

        return {
            element,
            show(node) {
                element.querySelectorAll('button').forEach((button) => {
                    const value = node.attrs[button.dataset.name] || '';
                    button.setAttribute('aria-pressed', value === button.dataset.value ? 'true' : 'false');
                });
            },
        };
    };

    Statamic.$bard.replaceExtension('image', ({ extension }) => extension.extend({
        addAttributes() {
            return {
                ...(this.parent ? this.parent() : {}),
                size: layout('size', SIZES),
                align: layout('align', ALIGNS),
            };
        },

        // Statamic's own photo box (Edit Image, Override Alt, Replace, Remove) stays; it is marked with the
        // photo's size and position, and gets a row of Size / Position buttons at the bottom.
        addNodeView() {
            const render = this.parent ? this.parent() : null;
            if (!render) return null;

            return (props) => {
                const view = render(props);
                const bar = layoutBar(props);
                let current = props.node;
                const refresh = () => {
                    mark(view.dom, current);
                    bar.show(current);
                    // Statamic draws the box after the photo has loaded and may redraw it; keep the row in it.
                    const box = view.dom && view.dom.querySelector('.bard-inline-image-container');
                    if (box && bar.element.parentElement !== box) box.appendChild(bar.element);
                };
                refresh();
                queueMicrotask(refresh);
                const observer = view.dom ? new MutationObserver(() => { if (!bar.element.isConnected || !bar.element.parentElement?.classList.contains('bard-inline-image-container')) refresh(); }) : null;
                observer?.observe(view.dom, { childList: true, subtree: true });

                if (typeof view.update === 'function') {
                    const update = view.update.bind(view);
                    view.update = (node, ...rest) => {
                        const handled = update(node, ...rest);
                        if (handled) {
                            current = node;
                            refresh();
                        }
                        return handled;
                    };
                }
                if (typeof view.destroy === 'function') {
                    const destroy = view.destroy.bind(view);
                    view.destroy = () => {
                        observer?.disconnect();
                        destroy();
                    };
                }
                return view;
            };
        },
    }));

    // Size: a filled photo filling more or less of the page. Position: the photo block beside lines of text.
    const icon = (body) => `<svg data-photo-icon viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">${body}</svg>`;
    const page = '<rect x="2.5" y="3.5" width="19" height="17" rx="2" fill="none" stroke="currentColor" stroke-width="1.5" opacity=".55"/>';
    const fill = (x, y, w, h) => `<rect x="${x}" y="${y}" width="${w}" height="${h}" rx="1" fill="currentColor"/>`;
    const lines = (d) => `<path d="${d}" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>`;
    const isPhoto = (editor) => editor.isActive('image');
    // Changing a photo replaces it; select it again so the buttons stay for the next choice.
    const setPhoto = (editor, attributes) => {
        const position = editor.state.selection.from;
        editor.chain().focus().updateAttributes('image', attributes).setNodeSelection(position).run();
    };
    const photoButton = (name, text, html, attributes) => ({
        name,
        text,
        html,
        command: (editor) => setPhoto(editor, attributes),
        active: (editor) => editor.isActive('image', attributes),
        visible: isPhoto,
    });

    // Shown in every Bard toolbar, but only while a photo is selected.
    Statamic.$bard.buttons(() => [
        photoButton('photo_small', t('Photo: small'), icon(page + fill(9, 9, 6, 6)), { size: 'small' }),
        photoButton('photo_medium', t('Photo: medium'), icon(page + fill(6, 7, 12, 10)), { size: 'medium' }),
        photoButton('photo_full', t('Photo: full width'), icon(fill(2.5, 3.5, 19, 17)), { size: null }),
        photoButton('photo_left', t('Photo: left, text beside it'), icon(fill(2.5, 4, 9, 8) + lines('M14.5 5h7M14.5 9h7M2.5 16h19M2.5 20h19')), { align: 'left' }),
        photoButton('photo_center', t('Photo: centred'), icon(fill(7, 3, 10, 9) + lines('M2.5 16h19M2.5 20h19')), { align: null }),
        photoButton('photo_right', t('Photo: right, text beside it'), icon(fill(12.5, 4, 9, 8) + lines('M2.5 5h7M2.5 9h7M2.5 16h19M2.5 20h19')), { align: 'right' }),
    ]);
});
