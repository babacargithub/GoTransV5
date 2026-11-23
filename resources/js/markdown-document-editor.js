import Editor from '@toast-ui/editor';
import '@toast-ui/editor/dist/i18n/fr-fr';
import '@toast-ui/editor/dist/toastui-editor.css';

const DOCUMENT_EDITOR_TOOLBAR_ITEMS = [
    ['heading', 'bold', 'italic', 'strike'],
    ['hr', 'quote'],
    ['ul', 'ol', 'indent', 'outdent'],
    ['table', 'link'],
];

/**
 * Word-like visual editor for a markdown document: the person edits formatted text, and the Livewire
 * property holds the equivalent markdown.
 *
 * @param {HTMLElement} editorContainer element the editor is mounted in
 * @param {string} initialMarkdown markdown to edit
 * @param {(markdown: string) => void} onMarkdownChange called on every edit with the new markdown
 */
window.mountMarkdownDocumentEditor = (editorContainer, initialMarkdown, onMarkdownChange) => {
    const editor = new Editor({
        el: editorContainer,
        initialValue: initialMarkdown,
        initialEditType: 'wysiwyg',
        hideModeSwitch: true,
        previewStyle: 'tab',
        height: '60vh',
        language: 'fr-FR',
        usageStatistics: false,
        toolbarItems: DOCUMENT_EDITOR_TOOLBAR_ITEMS,
        events: {
            change: () => onMarkdownChange(editor.getMarkdown()),
        },
    });

    return editor;
};
