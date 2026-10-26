/**
 * Ticket attachments (ClientFence, TicketFiles.php; partials/ticket_upload.html.twig): each file
 * chosen in a ticket form is uploaded at once (support/ticket_file_upload) and listed with a ×
 * (support/ticket_file_discard); its id goes into the form as ticket_files[], which the form
 * sends with the message. The form waits while files are still uploading.
 */
import { api, errorText } from './store-utils.js';

const notify = (text) => ClientFence.message(text, 'error');

function initUpload(box) {
  const form = box.closest('form');
  const input = box.querySelector('input[type="file"]');
  const list = box.querySelector('[data-clientfence-ticket-upload-list]');
  if (!form || !input || !list) {
    return;
  }
  const maxFiles = Number(box.dataset.maxFiles);
  const maxBytes = Number(box.dataset.maxBytes);
  const extensions = (box.dataset.extensions || '').split(',');
  let uploading = 0;

  const add = (file) => {
    const item = document.createElement('li');
    item.className = 'ticket-file border rounded px-2 py-1 d-inline-flex align-items-center gap-2';
    const name = document.createElement('span');
    name.dir = 'auto';
    name.textContent = file.name;
    const size = document.createElement('span');
    size.className = 'text-muted small';
    size.dir = 'ltr';
    size.textContent = file.size_label;
    const hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = 'ticket_files[]';
    hidden.value = file.id;
    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'btn-close btn-sm';
    remove.setAttribute('aria-label', box.dataset.textRemove);
    remove.addEventListener('click', () => {
      api('client', 'support/ticket_file_discard', { id: file.id })
        .then(() => item.remove())
        .catch((error) => notify(errorText(error)));
    });
    item.append(name, size, hidden, remove);
    list.append(item);
  };

  input.addEventListener('change', () => {
    for (const file of Array.from(input.files)) {
      const extension = file.name.includes('.') ? file.name.split('.').pop().toLowerCase() : '';
      if (list.children.length + uploading >= maxFiles) {
        notify(box.dataset.textTooMany);
        break;
      }
      if (!extensions.includes(extension)) {
        notify(box.dataset.textWrongType);
        continue;
      }
      if (file.size > maxBytes) {
        notify(box.dataset.textTooBig);
        continue;
      }
      const data = new FormData();
      data.append('file', file);
      if (box.dataset.ticket) {
        data.append('ticket_id', box.dataset.ticket);
      }
      uploading += 1;
      api('client', 'support/ticket_file_upload', data)
        .then(add)
        .catch((error) => notify(errorText(error)))
        .finally(() => {
          uploading -= 1;
        });
    }
    input.value = '';
  });

  // Run before the page's own handlers (capture), so a message never leaves without its files.
  // The click guard also keeps the page's "Post" handler from covering the page with its loader.
  const wait = (event) => {
    event.preventDefault();
    event.stopImmediatePropagation();
    notify(box.dataset.textUploading);
  };
  form.addEventListener('click', (event) => {
    if (uploading > 0 && event.target.closest('[type="submit"], button:not([type])')) {
      wait(event);
    }
  }, true);
  form.addEventListener('submit', (event) => {
    if (uploading > 0) {
      wait(event);
    }
  }, true);
}

export default function initTicketFiles() {
  document.querySelectorAll('[data-clientfence-ticket-upload]').forEach(initUpload);
}
