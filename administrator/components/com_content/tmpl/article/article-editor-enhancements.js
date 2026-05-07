// article-editor-enhancements.js
// Adds live word/character count and unsaved changes warning to the Joomla article editor (TinyMCE or textarea)

document.addEventListener('DOMContentLoaded', function () {
    // Find the article editor textarea or TinyMCE iframe
    var textarea = document.getElementById('jform_articletext');
    if (!textarea) return;

    // Create counter display
    var counter = document.createElement('div');
    counter.id = 'article-word-char-count';
    counter.style.marginTop = '8px';
    counter.style.fontSize = '0.95em';
    counter.style.color = '#666';
    textarea.parentNode.appendChild(counter);

    // Helper to get text content from TinyMCE or textarea
    function getEditorText() {
        if (window.tinymce && tinymce.get('jform_articletext')) {
            return tinymce.get('jform_articletext').getContent({ format: 'text' });
        }
        return textarea.value;
    }

    // Update counter
    function updateCounter() {
        var text = getEditorText();
        var words = text.trim().split(/\s+/).filter(Boolean).length;
        var chars = text.replace(/\s/g, '').length;
        counter.textContent = 'Words: ' + words + ' | Characters: ' + chars;
    }

    // Track changes for unsaved warning
    var isDirty = false;
    function markDirty() { isDirty = true; }
    function markClean() { isDirty = false; }

    // Attach events for textarea
    textarea.addEventListener('input', function () {
        updateCounter();
        markDirty();
    });

    // Attach events for TinyMCE
    if (window.tinymce) {
        tinymce.PluginManager.add('wordcharcount', function (editor) {
            editor.on('input change undo redo', function () {
                updateCounter();
                markDirty();
            });
            editor.on('SaveContent', function () {
                markClean();
            });
        });
        if (tinymce.get('jform_articletext')) {
            tinymce.get('jform_articletext').plugins.wordcharcount = true;
        }
    }

    // Mark clean on form submit
    var form = document.getElementById('item-form');
    if (form) {
        form.addEventListener('submit', function () {
            markClean();
        });
    }

    // Warn on unsaved changes
    window.addEventListener('beforeunload', function (e) {
        if (isDirty) {
            e.preventDefault();
            e.returnValue = '';
            return '';
        }
    });

    // Initial counter update
    updateCounter();
});
