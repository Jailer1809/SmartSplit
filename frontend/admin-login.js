'use strict';

const API = '../backend/index.php';
const form = document.getElementById('admin-login-form');
const submitButton = document.getElementById('admin-login-submit');
const message = document.getElementById('admin-login-message');
let csrfToken = '';

function showMessage(text, isError = false) {
  message.textContent = text;
  message.classList.remove('hidden');
  message.classList.toggle('error', isError);
  message.style.background = isError ? '#fff0ef' : '';
  message.style.color = isError ? '#a53840' : '';
}

async function getCsrfToken() {
  const response = await fetch(`${API}?action=csrf`, {
    method: 'GET',
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  });
  const result = await response.json();
  if (!response.ok || typeof result.csrf_token !== 'string') {
    throw new Error(result.error || 'The server could not start a secure sign-in session.');
  }
  csrfToken = result.csrf_token;
}

form.addEventListener('submit', async (event) => {
  event.preventDefault();
  submitButton.disabled = true;
  message.classList.add('hidden');
  try {
    const response = await fetch(`${API}?action=admin-login`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken,
      },
      body: JSON.stringify(Object.fromEntries(new FormData(form))),
    });
    const result = await response.json();
    if (!response.ok) {
      throw new Error(result.error || 'Administrator sign-in failed.');
    }
    if (result.user?.role !== 'admin') {
      throw new Error('This sign-in is restricted to administrator accounts.');
    }
    window.location.assign('index.html');
  } catch (error) {
    showMessage(error instanceof Error ? error.message : 'Administrator sign-in failed.', true);
    submitButton.disabled = false;
  }
});

getCsrfToken()
  .then(() => { submitButton.disabled = false; })
  .catch((error) => {
    showMessage(error instanceof Error ? error.message : 'Could not connect to the PHP API.', true);
  });
