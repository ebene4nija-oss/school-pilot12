'use client';

import React, { useEffect, useRef, useState } from 'react';
import { EditorContent, useEditor, useEditorState, type Editor } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import Image from '@tiptap/extension-image';
import { TableKit } from '@tiptap/extension-table';
import { Placeholder, CharacterCount } from '@tiptap/extensions';
import { Markdown } from '@tiptap/markdown';

/**
 * The blog's visual editor: write the way a reader will see it, WordPress
 * style, with headings, lists, links, quotes, tables and images placed in the
 * text. The post is still stored as Markdown (the public site renders that),
 * so nothing about the site or older posts changes.
 */

export default function RichEditor({
  value,
  onChange,
  onUpload,
  onStats,
  onHtml,
}: {
  /** Markdown. Read once when the editor opens; after that the editor owns the text. */
  value: string;
  onChange: (markdown: string, html: string) => void;
  /** Uploads an image and returns its public address. */
  onUpload: (file: File) => Promise<string>;
  onStats?: (words: number) => void;
  /** The rendered HTML, on open and after every change (for a preview). */
  onHtml?: (html: string) => void;
}) {
  const fileRef = useRef<HTMLInputElement>(null);
  const [uploading, setUploading] = useState(false);
  const [uploadError, setUploadError] = useState<string | null>(null);
  // The editor is created once; its handlers read the latest props through these.
  const onChangeRef = useLatest(onChange);
  const onStatsRef = useLatest(onStats);
  const onHtmlRef = useLatest(onHtml);
  const onUploadRef = useLatest(onUpload);
  const editorRef = useRef<Editor | null>(null);

  const editor = useEditor({
    // Rendered on the client only: Next pre-renders pages, and ProseMirror needs the DOM.
    immediatelyRender: false,
    extensions: [
      StarterKit.configure({
        heading: { levels: [2, 3] },
        link: { openOnClick: false, autolink: true, defaultProtocol: 'https' },
      }),
      Image.configure({ inline: false }),
      TableKit.configure({ table: { resizable: false } }),
      Placeholder.configure({ placeholder: 'Start writing your post… Use the toolbar for headings, lists, images and tables.' }),
      CharacterCount,
      Markdown,
    ],
    content: value,
    contentType: 'markdown',
    editorProps: {
      attributes: { class: 'sp-article', 'aria-label': 'Post body' },
      // Pasted or dropped image files upload and land where they were dropped.
      handlePaste: (_view, event) => {
        const file = Array.from(event.clipboardData?.files ?? []).find((f) => f.type.startsWith('image/'));
        if (!file) return false;
        void insertImage(file);
        return true;
      },
      handleDrop: (_view, event) => {
        const file = Array.from((event as DragEvent).dataTransfer?.files ?? []).find((f) => f.type.startsWith('image/'));
        if (!file) return false;
        event.preventDefault();
        void insertImage(file);
        return true;
      },
    },
    onUpdate: ({ editor: e }) => {
      onChangeRef.current(e.getMarkdown(), e.getHTML());
      onHtmlRef.current?.(e.getHTML());
      onStatsRef.current?.(e.storage.characterCount.words());
    },
    onCreate: ({ editor: e }) => {
      editorRef.current = e;
      onHtmlRef.current?.(e.getHTML());
      onStatsRef.current?.(e.storage.characterCount.words());
    },
  });

  async function insertImage(file: File) {
    const target = editorRef.current;
    if (!target) return;
    setUploading(true);
    setUploadError(null);
    try {
      const src = await onUploadRef.current(file);
      const alt = window.prompt('Describe the image for readers who cannot see it (optional):', '') ?? '';
      target.chain().focus().setImage({ src, alt }).run();
    } catch (err) {
      setUploadError(err instanceof Error ? err.message : 'That image could not be uploaded.');
    } finally {
      setUploading(false);
    }
  }

  return (
    <div className="sp-editor rounded-xl bg-surface ring-1 ring-line-soft">
      {editor && <Toolbar editor={editor} uploading={uploading} onImage={() => fileRef.current?.click()} />}
      <input
        ref={fileRef}
        type="file"
        accept="image/png,image/jpeg,image/webp,image/gif"
        className="sr-only"
        onChange={(e) => {
          const file = e.target.files?.[0];
          e.target.value = '';
          if (file) void insertImage(file);
        }}
      />
      {uploadError && <p className="mx-6 mt-3 rounded-lg bg-negative-soft px-3 py-2 text-sm text-negative">{uploadError}</p>}
      <div className="px-6 py-5 md:px-10">
        <EditorContent editor={editor} />
      </div>
    </div>
  );
}

// ------------------------------------------------------------------ toolbar

function Toolbar({ editor, uploading, onImage }: { editor: Editor; uploading: boolean; onImage: () => void }) {
  // Re-render the toolbar when the selection or marks change, so buttons show what is on.
  const state = useEditorState({
    editor,
    selector: ({ editor: e }) => ({
      block: e.isActive('heading', { level: 2 }) ? 'h2' : e.isActive('heading', { level: 3 }) ? 'h3' : 'p',
      bold: e.isActive('bold'),
      italic: e.isActive('italic'),
      strike: e.isActive('strike'),
      code: e.isActive('code'),
      link: e.isActive('link'),
      bullet: e.isActive('bulletList'),
      ordered: e.isActive('orderedList'),
      quote: e.isActive('blockquote'),
      table: e.isActive('table'),
      canUndo: e.can().undo(),
      canRedo: e.can().redo(),
    }),
  });

  const chain = () => editor.chain().focus();

  function setLink() {
    const current = editor.getAttributes('link').href as string | undefined;
    const url = window.prompt('Link address (leave empty to remove the link):', current ?? 'https://');
    if (url === null) return;
    if (url.trim() === '' || url.trim() === 'https://') {
      chain().extendMarkRange('link').unsetLink().run();
      return;
    }
    chain().extendMarkRange('link').setLink({ href: url.trim() }).run();
  }

  return (
    <div role="toolbar" aria-label="Formatting" className="sticky top-0 z-10 flex flex-wrap items-center gap-1 rounded-t-xl border-b border-line-soft bg-surface/95 px-3 py-2 backdrop-blur">
      <select
        aria-label="Text style"
        className="mr-1 rounded-md border border-line bg-surface px-2 py-1.5 text-sm"
        value={state.block}
        onChange={(e) => {
          const v = e.target.value;
          if (v === 'p') chain().setParagraph().run();
          else chain().toggleHeading({ level: v === 'h2' ? 2 : 3 }).run();
        }}
      >
        <option value="p">Paragraph</option>
        <option value="h2">Heading</option>
        <option value="h3">Subheading</option>
      </select>
      <Sep />
      <Tool label="Bold (Ctrl+B)" on={state.bold} onClick={() => chain().toggleBold().run()}><b>B</b></Tool>
      <Tool label="Italic (Ctrl+I)" on={state.italic} onClick={() => chain().toggleItalic().run()}><i className="font-serif">I</i></Tool>
      <Tool label="Strikethrough" on={state.strike} onClick={() => chain().toggleStrike().run()}><s>S</s></Tool>
      <Tool label="Link (Ctrl+K)" on={state.link} onClick={setLink}>🔗</Tool>
      <Tool label="Inline code" on={state.code} onClick={() => chain().toggleCode().run()}><span className="font-mono text-xs">{'</>'}</span></Tool>
      <Sep />
      <Tool label="Bulleted list" on={state.bullet} onClick={() => chain().toggleBulletList().run()}>•≡</Tool>
      <Tool label="Numbered list" on={state.ordered} onClick={() => chain().toggleOrderedList().run()}>1≡</Tool>
      <Tool label="Quote" on={state.quote} onClick={() => chain().toggleBlockquote().run()}>❝</Tool>
      <Tool label="Divider" onClick={() => chain().setHorizontalRule().run()}>―</Tool>
      <Sep />
      <Tool label="Insert image" onClick={onImage} disabled={uploading}>{uploading ? '…' : '🖼'}</Tool>
      <Tool label="Insert table" onClick={() => chain().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run()}>▦</Tool>
      {state.table && (
        <>
          <Tool label="Add row below" onClick={() => chain().addRowAfter().run()}><span className="text-xs">+Row</span></Tool>
          <Tool label="Add column right" onClick={() => chain().addColumnAfter().run()}><span className="text-xs">+Col</span></Tool>
          <Tool label="Delete row" onClick={() => chain().deleteRow().run()}><span className="text-xs">−Row</span></Tool>
          <Tool label="Delete column" onClick={() => chain().deleteColumn().run()}><span className="text-xs">−Col</span></Tool>
          <Tool label="Delete table" onClick={() => chain().deleteTable().run()}><span className="text-xs">✕Table</span></Tool>
        </>
      )}
      <span className="ml-auto flex gap-1">
        <Tool label="Undo (Ctrl+Z)" onClick={() => chain().undo().run()} disabled={!state.canUndo}>↶</Tool>
        <Tool label="Redo (Ctrl+Shift+Z)" onClick={() => chain().redo().run()} disabled={!state.canRedo}>↷</Tool>
      </span>
    </div>
  );
}

function Tool({ label, on = false, disabled = false, onClick, children }: { label: string; on?: boolean; disabled?: boolean; onClick: () => void; children: React.ReactNode }) {
  return (
    <button
      type="button"
      title={label}
      aria-label={label}
      aria-pressed={on}
      disabled={disabled}
      // Keep the text selection: a mousedown on the button would otherwise blur the editor.
      onMouseDown={(e) => e.preventDefault()}
      onClick={onClick}
      className={`min-w-9 rounded-md px-2 py-1.5 text-sm transition disabled:opacity-40 ${on ? 'bg-brand text-white' : 'text-ink hover:bg-surface-sunken'}`}
    >
      {children}
    </button>
  );
}

function Sep() {
  return <span aria-hidden="true" className="mx-1 h-6 w-px bg-line-soft" />;
}

/** Keep a ref to the latest callback without re-creating the editor. */
function useLatest<T>(value: T) {
  const ref = useRef(value);
  useEffect(() => {
    ref.current = value;
  });
  return ref;
}
