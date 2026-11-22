// The editor behind the members' rich text boxes (public/js/rich-text.js). The site has no build step on
// the server, so this is bundled once into public/js/vendor/ with `npm run build:editor` and committed.
// When upgrading TipTap, change the version in package.json (dependencies and the script's file name).
import { Editor, Node, mergeAttributes } from '@tiptap/core';
import BubbleMenu from '@tiptap/extension-bubble-menu';
import Image from '@tiptap/extension-image';
import { NodeSelection } from '@tiptap/pm/state';
import StarterKit from '@tiptap/starter-kit';

// Node and mergeAttributes: blocks of the site's own, like videos in the text.
window.Tiptap = { Editor, StarterKit, Image, BubbleMenu, NodeSelection, Node, mergeAttributes };
