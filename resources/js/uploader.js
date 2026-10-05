/**
 * Owns file selection, drag and drop, and the local preview.
 *
 * Client side checks are a courtesy that spares the user a round trip. The
 * server re-validates every one of them before any bytes are sent upstream, so
 * nothing here is treated as authoritative.
 */

const KILOBYTE = 1024;
const MEGABYTE = 1024 * 1024;

export function formatBytes(bytes) {
    if (bytes >= MEGABYTE) {
        const megabytes = bytes / MEGABYTE;

        return `${megabytes >= 10 ? Math.round(megabytes) : megabytes.toFixed(1)} MB`;
    }

    return `${Math.max(1, Math.round(bytes / KILOBYTE))} KB`;
}

function extensionOf(name) {
    const separator = name.lastIndexOf('.');

    return separator === -1 ? '' : name.slice(separator + 1).toLowerCase();
}

function normalizeExtension(ext) {
    if (ext === 'jpeg') {
        return 'jpg';
    }

    return ext;
}

function readDimensions(source) {
    return new Promise((resolve) => {
        const image = new Image();

        image.onload = () => resolve({ width: image.naturalWidth, height: image.naturalHeight });
        image.onerror = () => resolve(null);
        image.src = source;
    });
}

export function createUploader({ container, config, messages, onChange }) {
    const input = container.querySelector('[data-file-input]');
    const dropzone = container.querySelector('[data-dropzone]');
    const preview = container.querySelector('[data-preview]');
    const previewImage = container.querySelector('[data-preview-image]');
    const nameField = container.querySelector('[data-preview-name]');
    const sizeField = container.querySelector('[data-preview-size]');
    const dimensionsField = container.querySelector('[data-preview-dimensions]');
    const errorField = container.querySelector('[data-upload-error]');

    let selectedFile = null;
    let objectUrl = null;

    const showError = (message) => {
        errorField.textContent = message;
        errorField.hidden = message === '';
    };

    const clearSelection = () => {
        selectedFile = null;

        if (objectUrl !== null) {
            URL.revokeObjectURL(objectUrl);
            objectUrl = null;
        }

        previewImage.removeAttribute('src');
        preview.hidden = true;
        preview.classList.add('hidden');
        dropzone.hidden = false;
        showError('');
        onChange(null);
    };

    const renderPreview = async (file) => {
        objectUrl = URL.createObjectURL(file);

        previewImage.src = objectUrl;
        nameField.textContent = file.name;
        nameField.title = file.name;
        sizeField.textContent = formatBytes(file.size);

        const dimensions = await readDimensions(objectUrl);

        if (selectedFile !== file) {
            return;
        }

        dimensionsField.textContent = dimensions === null
            ? messages.unknownDimensions
            : `${dimensions.width} × ${dimensions.height}`;

        dropzone.hidden = true;
        preview.hidden = false;
        preview.classList.remove('hidden');
        showError('');
        onChange(file);
    };

    const accept = (file) => {
        const ext = normalizeExtension(extensionOf(file.name));

        if (! config.acceptedExtensions.some((allowed) => normalizeExtension(allowed) === ext)) {
            showError(messages.wrongType.replace('%FORMATS%', config.formatsLabel));

            return false;
        }

        if (file.size > config.maxBytes) {
            showError(messages.tooLarge.replace('%SIZE%', config.maxSizeLabel));

            return false;
        }

        return true;
    };

    const select = (file) => {
        if (file === undefined || file === null) {
            clearSelection();

            return;
        }

        if (! accept(file)) {
            selectedFile = null;
            onChange(null);

            return;
        }

        selectedFile = file;

        void renderPreview(file);
    };



    input.addEventListener('change', () => {
        select(input.files?.[0]);

        // Reset so re-picking the same file still fires a change event.
        input.value = '';
    });

    const setDragging = (dragging) => {
        dropzone.dataset.dragging = dragging ? 'true' : 'false';
    };

    for (const eventName of ['dragenter', 'dragover']) {
        dropzone.addEventListener(eventName, (event) => {
            event.preventDefault();
            setDragging(true);
        });
    }

    for (const eventName of ['dragleave', 'dragend']) {
        dropzone.addEventListener(eventName, () => setDragging(false));
    }

    dropzone.addEventListener('drop', (event) => {
        event.preventDefault();
        setDragging(false);

        select(event.dataTransfer?.files?.[0]);
    });

    // Dropping anywhere outside the zone must not make the browser navigate
    // away from the page and lose the selection.
    const stopNavigation = (event) => event.preventDefault();

    container.addEventListener('dragover', stopNavigation);
    container.addEventListener('drop', stopNavigation);

    return {
        file: () => selectedFile,
        reset: clearSelection,
    };
}