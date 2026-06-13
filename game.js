'use strict';

const STATE = {
  code      : null,
  camp      : null,
  etat      : 'attente',
  tour      : 'Sud',
  casesNord : [5,5,5,5,5,5,5],
  casesSud  : [5,5,5,5,5,5,5],
  scoreNord : 0,
  scoreSud  : 0,
  pollingId : null,
};

const API = 'api.php';

/* ── AJAX ── */
function ajax(params) {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    if (params.method === 'GET') {
      xhr.open('GET', API + '?' + new URLSearchParams(params.data).toString());
      xhr.send();
    } else {
      xhr.open('POST', API);
      const fd = new FormData();
      for (const k in params.data) fd.append(k, params.data[k]);
      xhr.send(fd);
    }
    xhr.onload  = () => { try { resolve(JSON.parse(xhr.responseText)); } catch(e) { reject('Réponse invalide'); } };
    xhr.onerror = () => reject('Erreur réseau');
  });
}

/* ── TOAST ── */
function showToast(msg, dur = 2500) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.classList.add('visible');
  setTimeout(() => t.classList.remove('visible'), dur);
}

/* ── PANNEAU CONNEXION ── */
function toggleConnexionPanel() {
  const p = document.getElementById('connexionPanel');
  p.style.display = p.style.display === 'none' ? 'block' : 'none';
}

document.addEventListener('click', e => {
  const panel = document.getElementById('connexionPanel');
  const btn   = document.getElementById('btnConnexion');
  if (panel && !panel.contains(e.target) && !btn.contains(e.target))
    panel.style.display = 'none';
});

/* ── CRÉER UNE PARTIE ── */
async function creerPartie() {
  try {
    const res = await ajax({ method: 'POST', data: { action: 'creer' } });
    if (res.error) { showToast('Erreur : ' + res.error); return; }
    STATE.code = res.code;
    STATE.camp = 'Sud';
    updateStatusBar();
    updateLienAdversaire();
    document.getElementById('connexionPanel').style.display = 'none';
    showToast('Partie créée ! Code : ' + res.code);
    demarrerPolling();
  } catch(e) { showToast('Erreur serveur. Vérifiez XAMPP.'); }
}

/* ── REJOINDRE UNE PARTIE ── */
async function rejoindrepartie() {
  const code = document.getElementById('inputCode').value.trim().toUpperCase();
  if (code.length < 6) { showToast('Entrez un code valide'); return; }
  try {
    const res = await ajax({ method: 'POST', data: { action: 'rejoindre', code } });
    if (res.error) { showToast(res.error); return; }
    STATE.code = code;
    STATE.camp = 'Nord';
    document.getElementById('connexionPanel').style.display = 'none';
    showToast('Connecté en tant que Nord !');
    await rafraichirEtat();
    demarrerPolling();
  } catch(e) { showToast('Erreur serveur. Vérifiez XAMPP.'); }
}

/* ── POLLING ── */
function demarrerPolling() {
  if (STATE.pollingId) clearInterval(STATE.pollingId);
  STATE.pollingId = setInterval(rafraichirEtat, 1500);
}

function stopPolling() {
  if (STATE.pollingId) { clearInterval(STATE.pollingId); STATE.pollingId = null; }
}

/* ── RAFRAÎCHIR L'ÉTAT ── */
async function rafraichirEtat() {
  if (!STATE.code) return;
  try {
    const res = await ajax({ method: 'GET', data: { action: 'etat', code: STATE.code } });
    if (res.error) return;
    STATE.etat      = res.etat;
    STATE.tour      = res.tour;
    STATE.casesNord = res.cases_nord;
    STATE.casesSud  = res.cases_sud;
    STATE.scoreNord = res.score_nord;
    STATE.scoreSud  = res.score_sud;
    rendreEtat(res);
    if (res.etat === 'termine') stopPolling();
  } catch(e) {}
}

/* ── JOUER UN COUP ── */
async function jouerCoup(camp, idx) {
  if (STATE.etat !== 'en_cours') { showToast('La partie n\'a pas encore commencé.'); return; }
  if (camp !== STATE.camp)       { showToast('Ce ne sont pas vos cases.'); return; }
  if (STATE.tour !== STATE.camp) { showToast('Ce n\'est pas votre tour.'); return; }

  const cases = STATE.camp === 'Sud' ? STATE.casesSud : STATE.casesNord;
  if (cases[idx] === 0) { showToast('Case vide.'); return; }

  highlightCase(camp, idx);

  try {
    const res = await ajax({ method: 'POST', data: { action: 'jouer', code: STATE.code, camp, case_idx: idx } });
    if (res.error) { showToast(res.error); return; }
    STATE.tour      = res.tour      || STATE.tour;
    STATE.casesNord = res.cases_nord || STATE.casesNord;
    STATE.casesSud  = res.cases_sud  || STATE.casesSud;
    STATE.scoreNord = res.score_nord;
    STATE.scoreSud  = res.score_sud;
    rendreEtat(res);
    if (res.fin) { stopPolling(); afficherFinPartie(res); }
  } catch(e) { showToast('Erreur lors du coup.'); }
}

/* ── RENDU COMPLET ── */
function rendreEtat(res) {
  document.getElementById('scoreNord').textContent = res.score_nord;
  document.getElementById('scoreSud').textContent  = res.score_sud;

  rendreCases('Nord', res.cases_nord);
  rendreCases('Sud',  res.cases_sud);
  updateStatusBar(res);

  if (res.dernier_coup)
    document.getElementById('dernierCoup').textContent = res.dernier_coup;

  if (res.historique && res.historique.length) {
    const ol = document.getElementById('historique');
    ol.innerHTML = '';
    res.historique.forEach(h => {
      const li = document.createElement('li');
      li.textContent = h;
      ol.appendChild(li);
    });
  }

  const msg = document.getElementById('boardMessage');
  if (res.etat === 'attente') {
    msg.textContent = 'En attente de l\'adversaire';
    msg.style.color = 'rgba(210,180,120,0.7)';
  } else if (res.etat === 'en_cours') {
    if (res.tour === STATE.camp) {
      msg.textContent = '✊ À vous de jouer !';
      msg.style.color = 'rgba(230,200,100,0.95)';
    } else {
      msg.textContent = 'Tour de l\'adversaire…';
      msg.style.color = 'rgba(210,180,120,0.6)';
    }
  } else {
    msg.textContent = 'Partie terminée';
    msg.style.color = 'rgba(210,180,120,0.7)';
  }

  mettreAJourInteractivite(res);
}

/* ── RENDU DES CASES ── */
function rendreCases(camp, cases) {
  cases.forEach((val, idx) => {
    const nomCase  = camp === 'Nord' ? `N${idx+1}` : `S${idx+1}`;
    const cntEl    = document.getElementById('cnt'    + nomCase);
    const grainsEl = document.getElementById('grains' + nomCase);
    if (!cntEl) return;

    // Toujours afficher le chiffre
    cntEl.textContent   = val;
    cntEl.style.display = 'block';
    grainsEl.innerHTML  = '';

    if (val > 0 && val <= 12) {
      // Afficher les petits grains visuels
      for (let i = 0; i < val; i++) {
        const g = document.createElement('div');
        g.className = 'grain';
        grainsEl.appendChild(g);
      }
      // Masquer le chiffre si les grains suffisent visuellement
      cntEl.style.display = val > 5 ? 'block' : 'none';
    }
    // Si val > 12 ou val === 0 : juste le chiffre, pas de grains
  });
}

/* ── INTERACTIVITÉ ── */
function mettreAJourInteractivite(res) {
  const isMonTour = res.etat === 'en_cours' && res.tour === STATE.camp;
  for (let i = 0; i < 7; i++) {
    const elN = document.getElementById(`caseN${i+1}`);
    const elS = document.getElementById(`caseS${i+1}`);
    if (elN) elN.classList.toggle('case-disabled', !(isMonTour && STATE.camp === 'Nord' && res.cases_nord[i] > 0));
    if (elS) elS.classList.toggle('case-disabled', !(isMonTour && STATE.camp === 'Sud'  && res.cases_sud[i]  > 0));
  }
}

/* ── HIGHLIGHT ── */
function highlightCase(camp, idx) {
  const el = document.getElementById('case' + (camp === 'Nord' ? `N${idx+1}` : `S${idx+1}`));
  if (!el) return;
  el.classList.add('case-active-highlight');
  setTimeout(() => el.classList.remove('case-active-highlight'), 600);
}

/* ── BARRE DE STATUT ── */
function updateStatusBar(res) {
  const map = { attente: 'Attente', en_cours: 'En cours', termine: 'Terminée' };
  document.getElementById('displayCode').textContent = STATE.code || '–';
  document.getElementById('displayCamp').textContent = STATE.camp || '–';
  document.getElementById('displayTour').textContent = (res && res.tour)  ? res.tour          : STATE.tour;
  document.getElementById('displayEtat').textContent = (res && res.etat)  ? (map[res.etat] || res.etat) : 'Attente';
}

/* ── LIEN ADVERSAIRE ── */
function updateLienAdversaire() {
  if (!STATE.code) return;
  document.getElementById('lienAdversaire').value =
    `${window.location.href.split('?')[0]}?code=${STATE.code}&camp=Nord`;
}

function toggleLienVisible() {
  const i = document.getElementById('lienAdversaire');
  i.type = i.type === 'password' ? 'text' : 'password';
}

function copyLien() {
  const val = document.getElementById('lienAdversaire').value;
  if (!val) { showToast('Aucun lien disponible'); return; }
  navigator.clipboard.writeText(val).then(() => showToast('Lien copié !'));
}

function copyCode() {
  const code = document.getElementById('displayCode').textContent;
  if (code === '–') { showToast('Aucun code'); return; }
  navigator.clipboard.writeText(code).then(() => showToast('Code copié !'));
}

/* ── FIN DE PARTIE ── */
function afficherFinPartie(res) {
  document.getElementById('finScoreNord').textContent = res.score_nord;
  document.getElementById('finScoreSud').textContent  = res.score_sud;
  let titre = 'Fin de partie !';
  if      (res.gagnant === STATE.camp)   titre = '🏆 Vous avez gagné !';
  else if (res.gagnant === 'Egalite')    titre = 'Égalité !';
  else                                   titre = 'Vous avez perdu.';
  document.getElementById('finTitre').textContent = titre;
  document.getElementById('modalFin').style.display = 'flex';
}

function nouvellePartie() {
  document.getElementById('modalFin').style.display = 'none';
  STATE.code = null; STATE.camp = null; STATE.etat = 'attente';
  stopPolling();
  const init = [5,5,5,5,5,5,5];
  rendreCases('Nord', init);
  rendreCases('Sud',  init);
  ['scoreNord','scoreSud'].forEach(id => document.getElementById(id).textContent = '0');
  ['displayCode','displayCamp','displayTour'].forEach(id => document.getElementById(id).textContent = '–');
  document.getElementById('displayEtat').textContent  = 'Attente';
  document.getElementById('dernierCoup').textContent  = '–';
  document.getElementById('historique').innerHTML     = '';
  document.getElementById('boardMessage').textContent = 'En attente de l\'adversaire';
  document.getElementById('lienAdversaire').value     = '';
  showToast('Nouvelle partie !');
}

/* ── MODALES ── */
function showRegles() { document.getElementById('modalRegles').style.display = 'flex'; }
function showPrise()  { document.getElementById('modalPrise').style.display  = 'flex'; }
function closeModal(id) { document.getElementById(id).style.display = 'none'; }

/* ── QUITTER ── */
function quitterPartie() {
  if (!STATE.code) { showToast('Aucune partie en cours.'); return; }
  if (confirm('Quitter la partie en cours ?')) { stopPolling(); nouvellePartie(); }
}

/* ── AUTO-REJOINDRE VIA URL ── */
(async function autoJoin() {
  const params = new URLSearchParams(window.location.search);
  const code   = params.get('code');
  const camp   = params.get('camp');
  if (!code || !camp) return;
  STATE.camp = camp;
  STATE.code = code.toUpperCase();
  if (camp === 'Nord') {
    try {
      const res = await ajax({ method: 'POST', data: { action: 'rejoindre', code: STATE.code } });
      if (!res.error) { showToast('Connecté comme Nord !'); updateStatusBar(); updateLienAdversaire(); }
      else showToast(res.error);
    } catch(e) {}
  }
  await rafraichirEtat();
  demarrerPolling();
})();

/* ── INIT VISUEL AU DÉMARRAGE ── */
rendreCases('Nord', [5,5,5,5,5,5,5]);
rendreCases('Sud',  [5,5,5,5,5,5,5]);
