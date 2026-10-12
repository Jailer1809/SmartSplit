'use strict';

const API = '../backend/index.php';
const categories = ['savings', 'emi', 'rent', 'other'];
const categoryNames = { income: 'Income', savings: 'Savings', emi: 'Loan / EMI', rent: 'Rent / housing', other: 'Other expenses' };
const monetaryRiskFactors = new Set(['monthly_income', 'savings', 'emi', 'rent', 'other_expenses']);
let csrfToken = '';
let currentUser = null;
let dashboardData = null;
let activePage = 'overview';

const byId = (id) => document.getElementById(id);
const money = (value) => new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', minimumFractionDigits: 0, maximumFractionDigits: 2 }).format(Number(value || 0));
const formatRiskFactorValue = (factor) => monetaryRiskFactors.has(factor.factor)
  ? money(factor.value)
  : Number(factor.value).toLocaleString('en-IN');
const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]);
const currentMonth = () => {
  const date = new Date();
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
};

async function request(action, { method = 'GET', data, retryCsrf = true } = {}) {
  if (window.location.protocol === 'file:') {
    throw new Error('This app must be opened through XAMPP (HTTP), not directly as a file. Start Apache and MySQL, then open http://localhost/XAI/frontend/.');
  }
  const url = new URL(API, window.location.href);
  url.searchParams.set('action', action);
  if (method === 'GET' && data) {
    Object.entries(data).forEach(([key, value]) => url.searchParams.set(key, value));
  }
  const options = { method, credentials: 'same-origin', headers: { Accept: 'application/json' } };
  if (method !== 'GET') {
    options.headers['Content-Type'] = 'application/json';
    options.headers['X-CSRF-Token'] = csrfToken;
    options.body = JSON.stringify(data || {});
  }
  let response;
  try {
    response = await fetch(url, options);
  } catch {
    throw new Error('Cannot reach the PHP API. Start Apache and MySQL in XAMPP, import database/schema.sql, and open this app at http://localhost/XAI/frontend/.');
  }
  let result;
  try {
    result = await response.json();
  } catch {
    throw new Error('The server returned an unreadable response.');
  }
  if (
    !response.ok
    && response.status === 403
    && method !== 'GET'
    && retryCsrf
    && result.error === 'The request token is invalid or expired. Refresh the page and try again.'
  ) {
    await loadCsrf();
    return request(action, { method, data, retryCsrf: false });
  }
  if (!response.ok) throw new Error(result.error || 'The request could not be completed.');
  return result;
}

async function closeDemoLoan(id) {
  try {
    const result = await request('loan-commitments/close-demo', { method: 'POST', data: { commitment_id: id } });
    await loadDashboard();
    showToast(result.message);
  } catch (error) {
    showToast(error.message, true);
  }
}

async function markNotificationsRead() {
  if (!dashboardData) return;
  const ids = dashboardData.notifications.filter((item) => Number(item.is_read) === 0).map((item) => Number(item.id));
  if (!ids.length) return;
  try {
    await request('notifications/read', { method: 'POST', data: { ids } });
    await loadDashboard();
  } catch (error) {
    showToast(error.message, true);
  }
}

function showToast(message, error = false) {
  const toast = byId('toast');
  toast.textContent = message;
  toast.classList.toggle('error', error);
  toast.classList.remove('hidden');
  window.clearTimeout(showToast.timeout);
  showToast.timeout = window.setTimeout(() => toast.classList.add('hidden'), 4500);
}

async function copyCustomerReference() {
  const reference = byId('customer-reference-id').textContent;
  if (!reference || reference === 'Loading…' || reference === 'Unavailable') {
    showToast('Your SmartSplit ID is not available yet.', true);
    return;
  }
  try {
    await navigator.clipboard.writeText(reference);
    showToast('SmartSplit ID copied. Share it only with a verifier you trust.');
  } catch {
    showToast(`Your SmartSplit ID is ${reference}. Copy it manually.`, true);
  }
}

function showAuthMessage(message, error = false) {
  const element = byId('auth-message');
  element.textContent = message;
  element.classList.toggle('error', error);
  element.classList.remove('hidden');
  element.style.background = error ? '#fff0ef' : '';
  element.style.color = error ? '#a53840' : '';
}

async function loadCsrf() {
  const response = await request('csrf');
  csrfToken = response.csrf_token;
  return response.user;
}

function switchAuthTab(tabName) {
  document.querySelectorAll('[data-auth-tab]').forEach((button) => button.classList.toggle('selected', button.dataset.authTab === tabName));
  byId('login-form').classList.toggle('hidden', tabName !== 'login');
  byId('register-form').classList.toggle('hidden', tabName !== 'register');
  byId('auth-message').classList.add('hidden');
}

function updateRegistrationFields() {
  const isBank = byId('registration-role').value === 'bank';
  const customerFields = byId('customer-registration-fields');
  const bankFields = byId('bank-registration-fields');
  customerFields.classList.toggle('hidden', isBank);
  bankFields.classList.toggle('hidden', !isBank);
  byId('pan-id').required = !isBank;
  byId('bank-registration-fields').querySelector('[name="bank_name"]').required = isBank;
  byId('bank-employee-id').required = isBank;
  if (isBank) {
    byId('pan-id').value = '';
  } else {
    bankFields.querySelectorAll('input').forEach((input) => { input.value = ''; });
  }
}

function navItemsForRole(role) {
  if (role === 'customer') return [['overview', 'Overview'], ['entries', 'Monthly details'], ['limits', 'Spending limits'], ['risk', 'Risk & explanation'], ['sharing', 'Bank access']];
  if (role === 'bank') return [['verification', 'Verification portal']];
  return [['administration', 'Admin panel']];
}

function renderNavigation(role) {
  const items = navItemsForRole(role);
  byId('app-view').classList.toggle('customer-mode', role === 'customer');
  byId('main-nav').innerHTML = items.map(([key, label], index) =>
    `<button class="nav-item${index === 0 ? ' active' : ''}" type="button" data-role-page="${key}">${escapeHtml(label)}</button>`
  ).join('');
  byId('customer-view').classList.toggle('hidden', role !== 'customer');
  byId('bank-view').classList.toggle('hidden', role !== 'bank');
  byId('admin-view').classList.toggle('hidden', role !== 'admin');
  activePage = role === 'customer' ? 'overview' : role === 'bank' ? 'verification' : 'administration';
}

async function enterApp(user) {
  currentUser = user;
  byId('auth-view').classList.add('hidden');
  byId('app-view').classList.remove('hidden');
  const roleLabel = user.role === 'bank' ? 'Bank Employee' : user.role === 'customer' ? 'Normal User' : 'Admin';
  const maskedPan = user.pan_id_masked ? ` · PAN ${user.pan_id_masked}` : '';
  byId('user-label').textContent = `${user.name} · ${roleLabel}${maskedPan}`;
  renderNavigation(user.role);
  if (user.role === 'customer') {
    const customerReference = user.customer_reference_id || 'Unavailable';
    byId('customer-reference-id').textContent = customerReference;
    byId('customer-reference-id-sharing').textContent = customerReference;
    byId('month-picker').value = currentMonth();
    await loadDashboard();
  } else if (user.role === 'admin') {
    await loadAdmin();
  }
}

async function initialize() {
  if (window.location.protocol === 'file:') {
    byId('hosting-error').innerHTML = 'This page was opened directly from a file, so sign-in and registration cannot reach PHP or MySQL. Start Apache and MySQL in XAMPP, place the project at <code>C:\\xampp\\htdocs\\XAI</code>, then open the app over HTTP.<br><a href="http://localhost/XAI/frontend/">Open the XAMPP app at http://localhost/XAI/frontend/</a>';
    byId('hosting-error').classList.remove('hidden');
    byId('login-form').reset();
    byId('register-form').reset();
    document.querySelectorAll('#login-form input, #register-form input, #register-form select, #login-form button, #register-form button').forEach((element) => {
      element.disabled = true;
    });
    return;
  }
  try {
    const user = await loadCsrf();
    if (user) await enterApp(user);
  } catch (error) {
    showAuthMessage(`Unable to connect to the PHP API: ${error.message}`, true);
  }
}

async function authSubmit(event, action) {
  event.preventDefault();
  const form = event.currentTarget;
  const values = Object.fromEntries(new FormData(form));
  if (action === 'register') {
    if (values.role === 'customer') {
      values.pan_id = String(values.pan_id || '').trim().toUpperCase();
      if (!/^[A-Z]{5}[0-9]{4}[A-Z]$/.test(values.pan_id)) {
        showAuthMessage('Enter a valid PAN ID: 5 letters, 4 digits, then 1 letter (for example ABCDE1234F).', true);
        return;
      }
    } else {
      values.bank_employee_id = String(values.bank_employee_id || '').trim().toUpperCase();
      values.bank_name = String(values.bank_name || '').trim();
      if (values.bank_name.length < 2 || values.bank_name.length > 120) {
        showAuthMessage('Enter a bank name between 2 and 120 characters.', true);
        return;
      }
      if (!/^[A-Z0-9]{4,32}$/.test(values.bank_employee_id)) {
        showAuthMessage('Bank Employee ID must contain 4–32 letters or digits.', true);
        return;
      }
    }
  }
  try {
    const result = await request(action, { method: 'POST', data: values });
    if (result.csrf_token) csrfToken = result.csrf_token;
    if (result.user) {
      if ('Notification' in window && Notification.permission === 'default') {
        Notification.requestPermission().catch(() => {});
      }
      await enterApp(result.user);
    } else {
      showAuthMessage(result.message);
      form.reset();
    }
  } catch (error) {
    showAuthMessage(error.message, true);
  }
}

function setActivePage(page) {
  activePage = page;
  document.querySelectorAll('[data-role-page]').forEach((button) => button.classList.toggle('active', button.dataset.rolePage === page));
  document.querySelectorAll('.customer-panel').forEach((panel) => panel.classList.toggle('hidden', panel.dataset.panel !== page));
  if (page === 'entries') fillFinanceForm();
  if (page === 'limits') fillLimitsForm();
}

async function loadDashboard() {
  const month = byId('month-picker').value || currentMonth();
  dashboardData = await request('dashboard', { data: { month } });
  renderDashboard();
}

function fillFinanceForm() {
  if (!dashboardData) return;
  const form = byId('finance-form');
  Object.entries(dashboardData.entries).forEach(([key, value]) => {
    if (key !== 'emi' && form.elements[key]) form.elements[key].value = value;
  });
  form.elements.on_time_payments.value = dashboardData.repayment.on_time;
  form.elements.missed_payments.value = dashboardData.repayment.missed;
  renderBudgetAlert(dashboardData.entries, byId('budget-alert'));
  renderBudgetAlert(readFinanceForm(), byId('finance-budget-preview'), true);
  renderLoanCommitments(dashboardData.loan_commitments, dashboardData.active_emi_count);
  renderDemoLoans(dashboardData.demo_loans);
}

function renderLoanCommitments(commitments, activeCount) {
  byId('emi-count').textContent = `${activeCount} active ${activeCount === 1 ? 'EMI' : 'EMIs'}`;
  const activeTotal = commitments
    .filter((item) => item.status === 'active')
    .reduce((total, item) => total + Number(item.monthly_emi), 0);
  byId('emi-total').textContent = activeCount
    ? `Combined monthly EMI: ${money(activeTotal)}`
    : `No active EMI commitments. Monthly EMI is ${money(0)}.`;
  if (!commitments.length) {
    byId('emi-commitment-list').innerHTML = '<div class="empty-state">No EMI commitments added yet.</div>';
    return;
  }
  byId('emi-commitment-list').innerHTML = `<table><thead><tr><th>Loan label</th><th>Monthly EMI</th><th>Status</th><th>Total amount paid</th><th>Paid on time</th><th>Action</th></tr></thead><tbody>${commitments.map((item) => `<tr><td>${escapeHtml(item.loan_label)}</td><td>${money(item.monthly_emi)}</td><td>${escapeHtml(item.status)}</td><td>${item.amount_paid === null ? '—' : money(item.amount_paid)}</td><td>${item.paid_on_time === null ? '—' : item.paid_on_time ? 'Yes' : 'No'}</td><td>${item.status === 'active' ? `<button class="button quiet" type="button" data-close-emi="${item.id}">Close loan</button>` : '—'}</td></tr>`).join('')}</tbody></table>`;
}

function demoLoanCards(loans) {
  const activeLoans = loans.filter((loan) => loan.status === 'active');
  const activeCount = activeLoans.length;
  const content = activeLoans.length
    ? activeLoans.map((loan) => `<article class="demo-loan-card">
        <div class="demo-loan-card-top"><span class="demo-loan-icon" aria-hidden="true">✦</span><span class="demo-loan-badge">FAKE · DEMO ONLY</span></div>
        <h3>${escapeHtml(loan.loan_label.replace(' · sample', ''))}</h3>
        <p>Sample loan record · not real debt</p>
        <div class="demo-loan-amount"><strong>${money(loan.monthly_emi)}</strong><span>monthly EMI</span></div>
        <div class="demo-loan-card-bottom"><span class="demo-loan-status">Active sample</span><button class="button quiet" type="button" data-close-demo-loan="${loan.id}">Close demo loan</button></div>
      </article>`).join('')
    : '<div class="demo-loan-empty">No demo loans are configured.</div>';
  return { activeCount, content };
}

function renderDemoLoans(loans) {
  const { activeCount, content } = demoLoanCards(loans);
  byId('demo-loan-count').textContent = `${activeCount} active sample ${activeCount === 1 ? 'loan' : 'loans'}`;
  byId('demo-loan-list').innerHTML = content;
  byId('overview-demo-loan-list').innerHTML = content;
}

function renderOverviewLoans(commitments, demoLoans, activeCount, activeTotal) {
  const activeLoans = commitments.filter((loan) => loan.status === 'active');
  byId('overview-real-loan-list').innerHTML = activeLoans.length
    ? `<div class="overview-real-heading"><strong>${activeCount} active ${activeCount === 1 ? 'EMI' : 'EMIs'}</strong><span>${money(activeTotal)} / month reserved</span></div><div class="overview-real-rows">${activeLoans.map((loan) => `<div class="overview-real-row"><span>${escapeHtml(loan.loan_label)}</span><strong>${money(loan.monthly_emi)} / month</strong><button class="button quiet" type="button" data-close-emi="${loan.id}">Close</button></div>`).join('')}</div>`
    : `<div class="overview-real-heading"><strong>No active real EMIs</strong><span>${money(0)} reserved</span></div><p class="overview-loan-note">Add a real loan commitment in Monthly details to reserve its monthly EMI.</p>`;
  const income = Number(dashboardData.entries.income || 0);
  const reservePercent = income > 0 ? Math.round(activeTotal / income * 100) : 0;
  byId('overview-vault-progress').innerHTML = income > 0
    ? `<div class="vault-progress-track" role="progressbar" aria-label="Monthly EMI reserve as a share of income" aria-valuenow="${Math.min(100, reservePercent)}" aria-valuemin="0" aria-valuemax="100"><span style="width:${Math.min(100, reservePercent)}%"></span></div><p>${money(activeTotal)} reserved · ${reservePercent}% of monthly income</p>`
    : '<p class="overview-loan-note">Add monthly income to see your EMI reserve against it.</p>';
  const demos = demoLoanCards(demoLoans);
  byId('overview-demo-loan-list').innerHTML = demos.content;
  document.querySelector('.overview-demo-wrap').classList.toggle('hidden', demos.activeCount === 0);
}

function renderOverviewExplanation(risk) {
  const element = byId('overview-explanation');
  if (!risk) {
    element.innerHTML = '<p class="overview-explanation-empty">Compute the educational score to see which self-reported factors influenced it.</p><button class="button secondary" type="button" data-check-score>Check demo score</button>';
    return;
  }
  const factors = (risk.explanation?.factors || []).slice(0, 4);
  element.innerHTML = factors.length
    ? `${factors.map((item) => {
      const impact = Number(item.impact);
      const favorable = impact >= 0;
      const factor = item.factor.replaceAll('_', ' ');
      const magnitude = Math.abs(impact) >= 20 ? 'high' : Math.abs(impact) >= 8 ? 'medium' : 'low';
      return `<div class="overview-factor ${favorable ? 'favorable' : 'unfavorable'}"><span aria-hidden="true">${favorable ? '+' : '−'}</span><span>${escapeHtml(factor)} <small>(${favorable ? 'supports' : 'lowers'} low-risk probability · ${magnitude} impact)</small></span><strong>${favorable ? '+' : ''}${impact.toFixed(1)} pts</strong></div>`;
    }).join('')}<p class="overview-explanation-note">Synthetic model explanation · not a credit-bureau score.</p>`
    : '<p class="overview-explanation-empty">No model explanation is available yet.</p>';
}

async function saveEmiCommitment(event) {
  event.preventDefault();
  const form = event.currentTarget;
  const values = Object.fromEntries(new FormData(form));
  try {
    const result = await request('loan-commitments/save', { method: 'POST', data: values });
    form.reset();
    await loadDashboard();
    renderLoanCommitments(dashboardData.loan_commitments, dashboardData.active_emi_count);
    showToast(result.message);
  } catch (error) {
    showToast(error.message, true);
  }
}

async function closeEmiCommitment(id) {
  const commitment = dashboardData.loan_commitments.find((item) => item.id === id);
  if (!commitment) return;
  byId('close-emi-label').textContent = `${commitment.loan_label} · Monthly EMI ${money(commitment.monthly_emi)}`;
  byId('close-emi-form').elements.commitment_id.value = String(id);
  byId('close-emi-dialog').showModal();
}

async function submitCloseEmi(event) {
  event.preventDefault();
  const form = event.currentTarget;
  const values = Object.fromEntries(new FormData(form));
  values.commitment_id = Number(values.commitment_id);
  try {
    const result = await request('loan-commitments/close', { method: 'POST', data: values });
    byId('close-emi-dialog').close();
    form.reset();
    await loadDashboard();
    if (activePage === 'entries') renderLoanCommitments(dashboardData.loan_commitments, dashboardData.active_emi_count);
    showToast(result.message);
  } catch (error) {
    showToast(error.message, true);
  }
}

function fillLimitsForm() {
  if (!dashboardData) return;
  const form = byId('limits-form');
  categories.forEach((category) => { form.elements[category].value = dashboardData.limits[category] ?? 0; });
}

function renderDashboard() {
  const { entries, limits, risk, notifications, consents } = dashboardData;
  renderBudgetAlert(entries, byId('budget-alert'));
  renderLoanCommitments(dashboardData.loan_commitments, dashboardData.active_emi_count);
  renderDemoLoans(dashboardData.demo_loans);
  renderOverviewLoans(
    dashboardData.loan_commitments,
    dashboardData.demo_loans,
    dashboardData.active_emi_count,
    dashboardData.active_emi_total,
  );
  byId('welcome-title').textContent = `Good ${new Date().getHours() < 12 ? 'morning' : new Date().getHours() < 18 ? 'afternoon' : 'evening'}, ${currentUser.name.split(' ')[0]}`;
  const outflow = Number(entries.emi) + Number(entries.rent) + Number(entries.other);
  const availableBudget = Number(entries.income) - outflow - Number(entries.savings);
  byId('summary-cards').innerHTML = [
    ['Monthly Income', money(entries.income), 'Self-reported for selected month', 'income'],
    ['EMI Vault (Reserved)', money(dashboardData.active_emi_total), `${dashboardData.active_emi_count} active real ${dashboardData.active_emi_count === 1 ? 'EMI' : 'EMIs'}`, 'emi'],
    ['Available Budget', money(availableBudget), 'Income less expenses and savings goal', 'available'],
    ['Financial Health Score', risk ? `${Number(risk.score).toFixed(0)} / 100` : 'Not checked', 'Educational model · not CIBIL', 'score'],
  ].map(([label, amount, caption, kind]) => `<article class="summary-card summary-${kind}"><span class="label">${escapeHtml(label)}</span><strong>${escapeHtml(amount)}</strong><small>${escapeHtml(caption)}</small>${kind === 'score' ? '<button class="text-button" type="button" data-check-score>Check demo score →</button>' : ''}</article>`).join('');
  byId('progress-list').innerHTML = categories.map((category) => {
    const amount = Number(entries[category] || 0);
    const limit = Number(limits[category] || 0);
    const ratio = limit > 0 ? amount / limit : 0;
    const percent = limit > 0 ? Math.round(ratio * 100) : 0;
    const state = ratio >= 1 ? 'danger' : ratio >= .8 ? 'warning' : '';
    return `<div class="progress-row"><div class="progress-label">${categoryNames[category]}<small>${limit ? `${percent}% of limit` : 'No limit set'}</small></div><div class="progress-track"><div class="progress-fill ${state} ${category}" style="width:${Math.min(percent, 100)}%"></div></div><div class="progress-value">${money(amount)}${limit ? ` / ${money(limit)}` : ''}</div></div>`;
  }).join('');
  const exceededCategory = categories.find((category) => Number(limits[category] || 0) > 0 && Number(entries[category] || 0) >= Number(limits[category]));
  byId('overview-limit-alert').textContent = exceededCategory
    ? `Alert: ${categoryNames[exceededCategory]} ${Number(entries[exceededCategory]) > Number(limits[exceededCategory]) ? 'spending has exceeded' : 'has reached'} ${Math.round(Number(entries[exceededCategory]) / Number(limits[exceededCategory]) * 100)}% of its set limit.`
    : '';
  byId('overview-limit-alert').classList.toggle('warning', Boolean(exceededCategory && Number(entries[exceededCategory]) === Number(limits[exceededCategory])));
  const unread = notifications.filter((item) => Number(item.is_read) === 0);
  byId('notification-count').textContent = `${unread.length} unread`;
  byId('mark-notifications').classList.toggle('hidden', unread.length === 0);
  byId('notification-list').innerHTML = notifications.length ? notifications.map((item) =>
    `<div class="notification-row ${Number(item.is_read) === 0 ? 'unread' : ''}"><span class="notification-dot"></span><span>${escapeHtml(item.message)}</span><time>${new Date(item.created_at).toLocaleDateString()}</time></div>`
  ).join('') : '<div class="empty-state">No budget alerts yet. Set a category limit to get started.</div>';
  renderRisk(risk);
  renderOverviewExplanation(risk);
  renderConsents(consents);
  notifyBrowser(unread);
}

function readFinanceForm() {
  const form = byId('finance-form');
  return Object.fromEntries(['income', 'savings', 'rent', 'other'].map((category) => [
    category,
    Number(form.elements[category].value || 0),
  ]).concat([['emi', Number(dashboardData?.active_emi_total || 0)]]));
}

function budgetGuidance(entries) {
  const income = Number(entries.income || 0);
  const savings = Number(entries.savings || 0);
  const emi = Number(entries.emi || 0);
  const rent = Number(entries.rent || 0);
  const other = Number(entries.other || 0);
  const spending = emi + rent + other;
  const totalPlan = spending + savings;
  const gap = Math.max(0, totalPlan - income);
  if (income <= 0 || gap <= 0) return null;

  let remaining = gap;
  const actions = [];
  const otherCut = Math.min(remaining, other);
  if (otherCut > 0) {
    actions.push(`Reduce Other expenses by ${money(otherCut)}${otherCut >= other ? ` (from ${money(other)} to ${money(0)} if needed)` : ` (from ${money(other)} to ${money(other - otherCut)})`}. This is your most flexible spending category.`);
    remaining -= otherCut;
  }

  const savingsAdjustment = Math.min(remaining, savings);
  if (savingsAdjustment > 0) {
    actions.push(`If appropriate, temporarily lower your savings goal by ${money(savingsAdjustment)} (from ${money(savings)} to ${money(savings - savingsAdjustment)}). Savings is a goal, not spending.`);
    remaining -= savingsAdjustment;
  }

  if (remaining > 0) {
    actions.push(`Your EMI and rent still leave a ${money(remaining)} shortfall after the flexible categories. Review future housing costs or contact your lender about options; don't skip a required payment without discussing it first.`);
  }
  return { income, spending, savings, totalPlan, gap, actions };
}

function renderBudgetAlert(entries, element, preview = false) {
  const guidance = budgetGuidance(entries);
  if (!guidance) {
    element.classList.add('hidden');
    element.innerHTML = '';
    return;
  }
  const expenseShortfall = Math.max(0, guidance.spending - guidance.income);
  element.classList.toggle('critical', expenseShortfall > 0);
  element.innerHTML = `
    <h2>${preview ? 'Budget check: ' : ''}${expenseShortfall > 0 ? 'Expenses exceed your monthly income' : 'Your plan is above your monthly income'}</h2>
    <p>Income: <strong>${money(guidance.income)}</strong> · Expenses (EMI, rent &amp; other): <strong>${money(guidance.spending)}</strong> · Savings goal: <strong>${money(guidance.savings)}</strong>.</p>
    <p>${expenseShortfall > 0 ? 'Your expenses exceed income' : 'Your expenses fit within income, but expenses plus savings exceed income'} by <strong>${money(guidance.gap)}</strong>.</p>
    <p><strong>Where to adjust:</strong></p>
    <ul>${guidance.actions.map((action) => `<li>${escapeHtml(action)}</li>`).join('')}</ul>`;
  element.classList.remove('hidden');
}

function notifyBrowser(unread) {
  if (!('Notification' in window) || Notification.permission !== 'granted') return;
  const shown = new Set(JSON.parse(sessionStorage.getItem('shown-notifications') || '[]'));
  const fresh = unread.filter((item) => !shown.has(String(item.id)));
  fresh.forEach((item) => new Notification('SmartSplit budget alert', { body: item.message, tag: `budget-${item.id}` }));
  if (fresh.length) {
    fresh.forEach((item) => shown.add(String(item.id)));
    sessionStorage.setItem('shown-notifications', JSON.stringify([...shown]));
  }
}

function renderRisk(risk) {
  if (!risk) {
    byId('risk-card').innerHTML = '<div class="empty-state">Save monthly finance details, then compute a score to see your explanation.</div>';
    return;
  }
  const explanation = risk.explanation || {};
  const factors = explanation.factors || [];
  const maxImpact = Math.max(1, ...factors.map((item) => Math.abs(Number(item.impact))));
  const riskClass = String(risk.risk_category).toLowerCase();
  byId('risk-card').innerHTML = `
    <div class="risk-score-head"><div class="score-circle ${riskClass}">${Number(risk.score).toFixed(0)}</div><div><h3>${escapeHtml(risk.risk_category)} risk · ${Number(risk.score).toFixed(1)} / 100</h3><p>Repayment reliability score · computed ${new Date(risk.computed_at).toLocaleString()}</p></div></div>
    <div class="explanation-title">${escapeHtml(explanation.method || 'Model explanation')} · ${escapeHtml(explanation.explained_output || 'Probability of the Low-risk class')}</div>
    ${factors.map((item) => `<div class="factor-row"><span class="factor-name">${escapeHtml(item.factor.replaceAll('_', ' '))}<br><small>Input: ${escapeHtml(formatRiskFactorValue(item))}</small></span><div class="factor-bar"><span class="${item.impact >= 0 ? 'positive' : 'negative'}" style="width:${Math.max(3, Math.abs(item.impact) / maxImpact * 100)}%"></span></div><span class="factor-impact">${item.impact >= 0 ? '+' : ''}${Number(item.impact).toFixed(2)} pts</span></div>`).join('')}
    <p class="fine-print">SHAP shows each input's contribution to the model's Low-risk probability relative to its baseline. Positive contributions increase that probability; negative contributions reduce it.</p>`;
}

function renderConsents(consents) {
  byId('consent-list').innerHTML = consents.length ? consents.map((consent) =>
    `<div class="consent-row"><div><code>${escapeHtml(consent.reference_id)}</code><small>Expires ${new Date(consent.expires_at.replace(' ', 'T') + 'Z').toLocaleString()}</small></div><button class="button quiet" type="button" data-revoke-consent="${escapeHtml(consent.reference_id)}">Revoke access</button></div>`
  ).join('') : '<div class="empty-state card">No active share references.</div>';
}

async function saveFinance(event) {
  event.preventDefault();
  const values = Object.fromEntries(new FormData(event.currentTarget));
  values.month = byId('month-picker').value || currentMonth();
  try {
    const result = await request('finance/save', { method: 'POST', data: values });
    await loadDashboard();
    const guidance = budgetGuidance(dashboardData.entries);
    showToast(guidance
      ? `${result.message} Budget is over by ${money(guidance.gap)}. See the highlighted suggestions below.`
      : result.message);
  } catch (error) {
    showToast(error.message, true);
  }
}

async function saveLimits(event) {
  event.preventDefault();
  const form = event.currentTarget;
  const limits = Object.fromEntries(categories.map((category) => [category, form.elements[category].value]));
  try {
    const result = await request('limits/save', { method: 'POST', data: { limits, month: byId('month-picker').value || currentMonth() } });
    showToast(result.message);
    await loadDashboard();
  } catch (error) {
    showToast(error.message, true);
  }
}

async function computeRisk() {
  const button = byId('compute-risk');
  button.disabled = true;
  button.textContent = 'Computing…';
  try {
    await request('risk/compute', { method: 'POST', data: { month: byId('month-picker').value || currentMonth() } });
    await loadDashboard();
    showToast('Risk score and audit proof computed.');
  } catch (error) {
    showToast(error.message, true);
  } finally {
    button.disabled = false;
    button.textContent = 'Compute score';
  }
}

async function createConsent() {
  try {
    await request('consent/create', { method: 'POST' });
    await loadDashboard();
    showToast('A seven-day share reference was created.');
  } catch (error) {
    showToast(error.message, true);
  }
}

async function revokeConsent(referenceId) {
  try {
    await request('consent/revoke', { method: 'POST', data: { reference_id: referenceId } });
    await loadDashboard();
    showToast('Bank access revoked.');
  } catch (error) {
    showToast(error.message, true);
  }
}

async function lookupBank(event) {
  event.preventDefault();
  const customerReference = event.currentTarget.elements.customer_reference_id.value.trim().toUpperCase();
  const reference = event.currentTarget.elements.reference_id.value.trim();
  const resultBox = byId('bank-result');
  resultBox.innerHTML = '<div class="empty-state">Checking consent and verifying the audit chain…</div>';
  try {
    const result = await request('bank/lookup', { data: { customer_reference_id: customerReference, reference_id: reference } });
    renderBankResult(result);
  } catch (error) {
    resultBox.innerHTML = `<div class="notice">${escapeHtml(error.message)}</div>`;
  }
}

function renderBankResult(result) {
  const integrity = result.chain_integrity;
  const risk = result.risk;
  const factors = risk.explanation?.factors || [];
  const history = result.finance_history || [];
  byId('bank-result').innerHTML = `
    <div class="section-heading"><div><p class="eyebrow">CONSENT VERIFIED · ${escapeHtml(result.customer_reference_id)}</p><h2>${escapeHtml(result.customer_name)} · ${escapeHtml(risk.risk_category)} risk</h2></div></div>
    <div class="integrity-banner ${integrity.valid ? 'valid' : 'invalid'}">${integrity.valid ? '✓ Audit chain verified' : '⚠ Audit chain mismatch detected'} · ${integrity.checked_blocks} blocks checked</div>
    <div class="bank-result-grid">
      <div class="proof-card"><small>Prototype repayment risk score</small><strong>${Number(risk.score).toFixed(1)} / 100</strong><p class="fine-print">Synthetic model score, not a CIBIL score · computed ${new Date(risk.computed_at).toLocaleString()}</p></div>
      <div class="proof-card"><small>Risk-score record proof</small>${result.risk_proof?.length ? `<code>${escapeHtml(result.risk_proof[result.risk_proof.length - 1].block_hash)}</code>` : '<span class="fine-print">No block found for this score.</span>'}</div>
      <div class="proof-card"><small>Active EMI commitments</small><strong>${Number(result.active_emi_count || 0)}</strong><p class="fine-print">Self-reported count linked to the customer's registered PAN.</p></div>
    </div>
    <div class="card risk-card"><div class="explanation-title">Explainability · ${escapeHtml(risk.explanation?.method || 'SHAP')}</div>${factors.map((item) => `<div class="factor-row"><span class="factor-name">${escapeHtml(item.factor.replaceAll('_', ' '))}<br><small>Input: ${escapeHtml(formatRiskFactorValue(item))}</small></span><div class="factor-bar"><span class="${item.impact >= 0 ? 'positive' : 'negative'}" style="width:${Math.max(3, Math.min(100, Math.abs(item.impact) * 8))}%"></span></div><span class="factor-impact">${item.impact >= 0 ? '+' : ''}${Number(item.impact).toFixed(2)} pts</span></div>`).join('')}</div>
    <div class="section-heading"><div><p class="eyebrow">SELF-REPORTED LOAN HISTORY</p><h2>EMI commitments</h2></div></div>
    <div class="card table-card"><table><thead><tr><th>Loan</th><th>Monthly EMI</th><th>Status</th><th>Total paid</th><th>Paid on time</th></tr></thead><tbody>${(result.loan_commitments || []).length ? result.loan_commitments.map((item) => `<tr><td>${escapeHtml(item.loan_label)}</td><td>${money(item.monthly_emi)}</td><td>${escapeHtml(item.status)}</td><td>${item.amount_paid === null ? '—' : money(item.amount_paid)}</td><td>${item.paid_on_time === null ? '—' : item.paid_on_time ? 'Yes' : 'No'}</td></tr>`).join('') : '<tr><td colspan="5">No EMI commitments provided.</td></tr>'}</tbody></table></div>
    <div class="section-heading"><div><p class="eyebrow">SELF-REPORTED HISTORY</p><h2>Monthly finance</h2></div></div>
    <div class="card table-card"><table><thead><tr><th>Month</th><th>Category</th><th>Amount</th></tr></thead><tbody>${history.map((row) => `<tr><td>${escapeHtml(row.month)}</td><td>${escapeHtml(categoryNames[row.category] || row.category)}</td><td>${money(row.amount)}</td></tr>`).join('')}</tbody></table></div>`;
}

async function loadAdmin() {
  try {
    const [users, logs] = await Promise.all([request('admin/users'), request('admin/logs')]);
    renderAdmin(users.users, logs.logs);
  } catch (error) {
    showToast(error.message, true);
  }
}

function renderAdmin(users, logs) {
  byId('admin-users').innerHTML = `<table><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Verification details</th><th>Status</th><th>Action</th></tr></thead><tbody>${users.map((user) => {
    const approved = Number(user.is_approved) === 1;
    const verificationStatus = user.verification_status || '';
    const roleLabel = user.role === 'bank' ? 'Bank Employee' : user.role === 'customer' ? 'Normal User' : 'Admin';
    const verificationDetails = user.role === 'customer'
      ? `PAN ${escapeHtml(user.pan_id_masked || 'not provided')}`
      : user.role === 'bank'
        ? `${escapeHtml(user.bank_name || 'Bank not provided')} · ID ${escapeHtml(user.bank_employee_id || 'not provided')}`
        : '—';
    const accountStatus = user.role === 'bank' && verificationStatus === 'pending'
      ? 'Pending Admin Approval'
      : verificationStatus === 'rejected' ? 'ID rejected' : approved ? 'Active' : 'Suspended';
    const action = user.role === 'admin' ? '—' : user.role === 'bank' && verificationStatus !== 'verified'
      ? `<button class="button secondary" data-review-bank="${user.id}" data-verification-status="verified">Verify ID</button> <button class="button quiet" data-review-bank="${user.id}" data-verification-status="rejected">Reject</button>`
      : `<button class="button quiet" data-user-status="${user.id}" data-approved="${approved ? 'false' : 'true'}">${approved ? 'Suspend' : 'Enable'}</button>`;
    return `<tr><td>${escapeHtml(user.name)}</td><td>${escapeHtml(user.email)}</td><td><span class="role-badge">${roleLabel}</span></td><td>${verificationDetails}</td><td><span class="role-badge ${approved ? '' : 'pending'}">${escapeHtml(accountStatus)}</span></td><td>${action}</td></tr>`;
  }).join('')}</tbody></table>`;
  byId('admin-logs').innerHTML = `<table><thead><tr><th>When</th><th>Event</th><th>Actor</th><th>Details</th></tr></thead><tbody>${logs.map((log) => `<tr><td>${new Date(log.created_at).toLocaleString()}</td><td>${escapeHtml(log.event)}</td><td>${escapeHtml(log.actor_email || 'System')}</td><td>${escapeHtml(JSON.stringify(log.details || {}))}</td></tr>`).join('')}</tbody></table>`;
}

async function adminAction(action, data, successMessage) {
  try {
    const result = await request(action, { method: 'POST', data });
    showToast(result.message || successMessage);
    await loadAdmin();
  } catch (error) {
    showToast(error.message, true);
  }
}

async function retrainModel() {
  const button = byId('retrain-model');
  button.disabled = true;
  button.textContent = 'Retraining…';
  try {
    const result = await request('admin/retrain', { method: 'POST' });
    showToast(`Model retrained using ${result.model.rows} synthetic records.`);
    await loadAdmin();
  } catch (error) {
    showToast(error.message, true);
  } finally {
    button.disabled = false;
    button.textContent = 'Retrain synthetic model';
  }
}

async function logout() {
  try {
    await request('logout', { method: 'POST' });
  } catch (error) {
    showToast(error.message, true);
    return;
  }
  currentUser = null;
  dashboardData = null;
  byId('app-view').classList.add('hidden');
  byId('auth-view').classList.remove('hidden');
  csrfToken = '';
  await loadCsrf();
  switchAuthTab('login');
}

document.querySelectorAll('[data-auth-tab]').forEach((button) => button.addEventListener('click', () => switchAuthTab(button.dataset.authTab)));
byId('login-form').addEventListener('submit', (event) => authSubmit(event, 'login'));
byId('register-form').addEventListener('submit', (event) => authSubmit(event, 'register'));
byId('registration-role').addEventListener('change', updateRegistrationFields);
byId('pan-id').addEventListener('input', (event) => {
  event.currentTarget.value = event.currentTarget.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 10);
});
byId('bank-employee-id').addEventListener('input', (event) => {
  event.currentTarget.value = event.currentTarget.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 32);
});
byId('logout-button').addEventListener('click', logout);
byId('main-nav').addEventListener('click', (event) => {
  const button = event.target.closest('[data-role-page]');
  if (!button) return;
  if (currentUser.role === 'customer') setActivePage(button.dataset.rolePage);
  if (currentUser.role === 'bank') byId('bank-view').scrollIntoView({ behavior: 'smooth' });
  if (currentUser.role === 'admin') byId('admin-view').scrollIntoView({ behavior: 'smooth' });
});
document.addEventListener('click', (event) => {
  const button = event.target.closest('[data-go]');
  if (button) setActivePage(button.dataset.go);
  if (event.target.closest('#copy-customer-reference, [data-copy-customer-reference]')) copyCustomerReference();
  if (event.target.closest('[data-check-score]')) {
    setActivePage('risk');
    if (!dashboardData?.risk) computeRisk();
  }
});
byId('month-picker').addEventListener('change', async () => {
  try { await loadDashboard(); } catch (error) { showToast(error.message, true); }
  if (activePage === 'entries') fillFinanceForm();
  if (activePage === 'limits') fillLimitsForm();
});
byId('finance-form').addEventListener('submit', saveFinance);
byId('emi-form').addEventListener('submit', saveEmiCommitment);
byId('emi-commitment-list').addEventListener('click', (event) => {
  const button = event.target.closest('[data-close-emi]');
  if (button) closeEmiCommitment(Number(button.dataset.closeEmi));
});
byId('demo-loan-list').addEventListener('click', (event) => {
  const button = event.target.closest('[data-close-demo-loan]');
  if (button) closeDemoLoan(Number(button.dataset.closeDemoLoan));
});
byId('overview-demo-loan-list').addEventListener('click', (event) => {
  const button = event.target.closest('[data-close-demo-loan]');
  if (button) closeDemoLoan(Number(button.dataset.closeDemoLoan));
});
byId('overview-real-loan-list').addEventListener('click', (event) => {
  const button = event.target.closest('[data-close-emi]');
  if (button) closeEmiCommitment(Number(button.dataset.closeEmi));
});
byId('close-emi-form').addEventListener('submit', submitCloseEmi);
byId('cancel-close-emi').addEventListener('click', () => {
  byId('close-emi-form').reset();
  byId('close-emi-dialog').close();
});
byId('finance-form').addEventListener('input', () => {
  renderBudgetAlert(readFinanceForm(), byId('finance-budget-preview'), true);
});
byId('limits-form').addEventListener('submit', saveLimits);
byId('compute-risk').addEventListener('click', computeRisk);
byId('mark-notifications').addEventListener('click', markNotificationsRead);
byId('create-consent').addEventListener('click', createConsent);
byId('consent-list').addEventListener('click', (event) => {
  const button = event.target.closest('[data-revoke-consent]');
  if (button) revokeConsent(button.dataset.revokeConsent);
});
byId('bank-lookup-form').addEventListener('submit', lookupBank);
byId('admin-users').addEventListener('click', (event) => {
  const review = event.target.closest('[data-review-bank]');
  const status = event.target.closest('[data-user-status]');
  if (review) adminAction('admin/review-bank', {
    user_id: Number(review.dataset.reviewBank),
    verification_status: review.dataset.verificationStatus,
  });
  if (status) adminAction('admin/set-user-status', { user_id: Number(status.dataset.userStatus), is_approved: status.dataset.approved === 'true' });
});
byId('retrain-model').addEventListener('click', retrainModel);
updateRegistrationFields();
initialize();
