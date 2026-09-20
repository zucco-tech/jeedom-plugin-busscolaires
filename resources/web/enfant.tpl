<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Atkinson+Hyperlegible:wght@400;700&display=swap">
<style>
/* L'écran de l'enfant. Une seule chose à faire à la fois, jamais un
   calcul à poser, la couleur avant le chiffre. Tout ce qui pourrait se
   régler, se fermer ou se casser a été retiré : il n'y a rien à toucher. */
:root{
  --nuit:#12161b; --carte:#1e242b; --relief:#262e37; --trait:#39424c;
  --encre:#eef2f6; --douce:#93a0ad; --ambre:#e6a63f; --vert:#57ab7d;
  --rouge:#d1615d; --bleu:#6ea8d8;
  --police:"Atkinson Hyperlegible","Trebuchet MS",Verdana,sans-serif;
}
*{box-sizing:border-box}
html,body{background:var(--nuit)}
body{margin:0;color:var(--encre);font-family:var(--police);line-height:1.45;
  -webkit-text-size-adjust:100%}
.appli{max-width:520px;margin:0 auto;min-height:100vh;min-height:100dvh;
  display:flex;flex-direction:column;
  padding:max(10px,env(safe-area-inset-top)) 12px max(14px,env(safe-area-inset-bottom))}

.barre{display:flex;justify-content:space-between;align-items:baseline;
  padding:6px 6px 10px;font-size:15px;color:var(--douce)}
.barre b{color:var(--encre);font-size:19px;font-variant-numeric:tabular-nums}

.ecran{background:var(--carte);border-radius:20px;overflow:hidden;flex:1;
  display:flex;flex-direction:column;border:1px solid var(--trait)}

.bandeau{padding:16px 18px 14px;display:flex;gap:12px;align-items:center;
  border-bottom:1px solid var(--trait)}
.pastille{width:15px;height:15px;border-radius:50%;flex:0 0 auto;
  background:var(--etat,var(--ambre));
  box-shadow:0 0 0 5px color-mix(in srgb,var(--etat,var(--ambre)) 22%,transparent)}
.bandeau .quoi{font-size:18px;font-weight:700;color:var(--etat,var(--ambre))}

.verbe{padding:30px 20px 10px;text-align:center}
.badge{display:inline-flex;align-items:center;justify-content:center;
  min-width:88px;padding:8px 20px;border-radius:14px;font-size:44px;
  font-weight:700;color:#0e1216;margin-bottom:10px}
.gros{font-size:34px;font-weight:700;line-height:1.1;letter-spacing:-.02em;
  text-wrap:balance}
.petit{margin-top:12px;color:var(--douce);font-size:18px}

.horaire{margin:20px 16px 0;background:var(--relief);border-radius:16px;
  padding:15px 16px;display:flex;flex-direction:column;gap:13px}
.rang{display:flex;align-items:center;gap:13px;font-size:17px}
.rang .h{font-variant-numeric:tabular-nums;font-weight:700;min-width:58px}
.rang .quoi{flex:1;min-width:0}
.rang .quoi small{display:block;color:var(--douce);font-size:14px;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rang.pale{opacity:.42}
.rang .puce{width:10px;height:10px;border-radius:50%;background:var(--trait);flex:0 0 auto}
.rang.actif .puce{background:var(--vert);box-shadow:0 0 0 4px rgba(87,171,125,.2)}
.rang .fil{width:30px;height:30px;border-radius:9px;display:grid;place-items:center;
  font-weight:700;font-size:14px;color:#0e1216;flex:0 0 auto}

.bas{margin-top:auto;padding:16px 20px 18px;color:var(--douce);font-size:15px;
  border-top:1px solid var(--trait);display:flex;justify-content:space-between;gap:10px}
.bas span{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.vieux{color:var(--ambre)}
@media (max-height:640px){
  .verbe{padding-top:20px}.gros{font-size:29px}.badge{font-size:36px}
  .horaire{margin-top:14px;gap:10px}
}
</style>

<div class="appli">
  <div class="barre"><span id="qui">#nom#</span><b id="pendule">--:--</b></div>
  <div class="ecran" id="ecran">
    <div class="bandeau"><span class="pastille"></span><span class="quoi" id="etat">Un instant…</span></div>
    <div class="verbe">
      <div id="badge"></div>
      <div class="gros" id="gros">&nbsp;</div>
      <div class="petit" id="petit"></div>
    </div>
    <div class="horaire" id="horaire" hidden></div>
    <div class="bas"><span id="bas-g"></span><span id="bas-d"></span></div>
  </div>
</div>

<script>
'use strict';
(function(){

const $ = id => document.getElementById(id);
const mn = h => h ? (+h.slice(0,2))*60 + (+h.slice(3,5)) : null;
const hhmm = d => String(d.getHours()).padStart(2,"0")+":"+String(d.getMinutes()).padStart(2,"0");
const maintenant = () => { const d = new Date(); return d.getHours()*60 + d.getMinutes(); };
const RETARD = 15;

/* Les noms de ligne, d'arrêt et les couleurs viennent du GTFS du réseau.
   Ils sont donc posés comme du texte, jamais comme du HTML, et une
   couleur n'est acceptée que si c'est vraiment un code hexadécimal. */
const el = (tag, cls, texte) => {
  const n = document.createElement(tag);
  if (cls) n.className = cls;
  if (texte !== undefined && texte !== null) n.textContent = String(texte);
  return n;
};
const couleurSure = c =>
  /^#[0-9a-fA-F]{3,8}$/.test(String(c || "")) ? String(c) : "var(--trait)";

/* On dit « la A » et « le 170 ». */
const art = l => /^[A-Z]$/.test(String(l)) ? "la " : "le ";
const Art = l => art(l).replace(/^./, c => c.toUpperCase());
/* Le même quai porte deux noms selon la ligne : elle n'a pas à le savoir. */
const lieu = n => String(n||"").replace(/^P\+R\s+/,"").replace(/\s+Q\d+$/,"");

/* ------------------------------------------------------------------ */
/* Ce qu'on sait, et ce qu'on garde quand le réseau manque             */
/* ------------------------------------------------------------------ */
const MEMOIRE = "busscolaires_dernier";
let reveil = null;
let etat = { resume:null, plan:null, lu:null, horsligne:false,
             fini:{}, derniereRoute:null };

function lire(){
  try {
    const b = JSON.parse(localStorage.getItem(MEMOIRE) || "null");
    if (b && b.date === new Date().toISOString().slice(0,10)) {
      etat.resume = b.resume; etat.plan = b.plan; etat.lu = b.lu;
      etat.fini = b.fini || {}; etat.derniereRoute = b.derniereRoute || null;
    }
  } catch (e) {}
}
function ecrire(){
  try {
    localStorage.setItem(MEMOIRE, JSON.stringify({
      date: new Date().toISOString().slice(0,10),
      resume: etat.resume, plan: etat.plan, lu: etat.lu,
      fini: etat.fini, derniereRoute: etat.derniereRoute }));
  } catch (e) {}
}

async function demander(route, params){
  const u = new URL("api.php", location.href);
  u.searchParams.set("r", route);
  for (const [k,v] of Object.entries(params||{})) u.searchParams.set(k, v);
  const r = await fetch(u, {credentials:"same-origin", cache:"no-store"});
  if (!r.ok) throw new Error(route + " " + r.status);
  return r.json();
}

/* Un trajet commencé ne se rediscute pas : tant qu'elle est en route, on
   garde le plan qu'on lui a donné, même si le calcul en trouve un autre. */
function planGele(){
  const p = etat.plan, t = maintenant();
  return (p && p.depart && p.arrivee && t >= mn(p.depart) && t < mn(p.arrivee)) ? p : null;
}

async function rafraichir(){
  try {
    const r = await demander("resume");
    etat.resume = r; etat.lu = Date.now(); etat.horsligne = false;

    /* Un trajet arrivé à destination clôt la question : une fois rentrée,
       lui proposer un nouveau chemin vers la maison n'a aucun sens. */
    const t = maintenant();
    if (etat.plan && t >= mn(etat.plan.arrivee)) etat.fini[etat.plan.sens] = true;
    if (r.en_route) etat.derniereRoute = r.en_route;
    else if (etat.derniereRoute && t >= mn(etat.derniereRoute.heure_arrivee))
      etat.fini[etat.derniereRoute.sens] = true;

    const sansCar = !r.heure_car;
    const besoin = (r.car_manque || (r.jour_de_classe && sansCar))
                   && !etat.fini[r.car_manque ? (r.sens_manque || "aller")
                                              : (r.sens === "aller" ? "aller" : "retour")];
    if (besoin && !planGele()){
      const sens = r.car_manque ? (r.sens_manque || "aller")
                                : (r.sens === "aller" ? "aller" : "retour");
      const heure = (sens === "retour" && r.fin_cours
                     && mn(r.fin_cours) > maintenant())
        ? r.fin_cours : hhmm(new Date());
      const s = await demander("solutions", {sens:sens, heure:heure, limite:1});
      const t = (s.trajets || [])[0];
      etat.plan = t ? Object.assign({sens:sens}, t) : null;
    } else if (!besoin && !planGele()) {
      etat.plan = null;
    }
    ecrire();
  } catch (e) {
    etat.horsligne = true;      // on garde le dernier écran connu
  }
  /* Tant que ça ne répond pas, on réessaie souvent : une panne de dix
     secondes ne doit pas lui coûter cinq minutes d'attente. */
  clearTimeout(reveil);
  reveil = setTimeout(rafraichir, etat.horsligne ? 10000 : 60000);
  dessiner();
}

/* ------------------------------------------------------------------ */
/* Ce qu'elle doit faire, à cette minute-ci                            */
/* ------------------------------------------------------------------ */
function etapesPlan(t, plan){
  const e1 = plan.etapes[0], e2 = plan.etapes[1] || null;
  const versEcole = plan.sens === "aller";
  const but = versEcole ? "jusqu'au lycée" : "jusqu'à la maison";
  const partir = mn(plan.depart), aBus = partir + (plan.marche_debut_min || 0);
  const d1 = mn(e1.heure_depart), a1 = mn(e1.heure_arrivee);
  const d2 = e2 ? mn(e2.heure_depart) : null, a2 = e2 ? mn(e2.heure_arrivee) : a1;
  const rangs = [
    {h:plan.depart, quoi:"Marche jusqu'à "+lieu(e1.de),
     sous:(plan.marche_debut_min||0)+" minutes à pied", actif:t>=partir && t<aBus},
    {h:e1.heure_depart, quoi:Art(e1.ligne)+e1.ligne, sous:"descends à "+lieu(e1.a),
     fil:e1.ligne, couleur:e1.couleur, actif:t>=d1 && t<a1},
  ];
  if (e2) rangs.push({h:e2.heure_depart, quoi:Art(e2.ligne)+e2.ligne,
     sous:"descends à "+lieu(e2.a), fil:e2.ligne, couleur:e2.couleur,
     actif:t>=d2 && t<a2});
  rangs.push({h:plan.arrivee, quoi:"Marche "+but,
     sous:(plan.marche_fin_min||0)+" minutes à pied", actif:t>=a2});

  if (t < partir)
    return {etat:"On prend l'autre route", couleur:"var(--rouge)",
      gros:"Tu pars à "+plan.depart, petit:"Vers "+lieu(e1.de)+".", rangs};
  if (t < aBus)
    return {etat:"En route", couleur:"var(--vert)", gros:"Marche jusqu'à "+lieu(e1.de),
      petit:(plan.marche_debut_min||0)+" minutes à pied. "
           +Art(e1.ligne)+e1.ligne+" est à "+e1.heure_depart+".", rangs};
  if (t < d1)
    return {etat:"Attends là", couleur:"var(--ambre)", badge:e1,
      gros:"Prends "+art(e1.ligne)+e1.ligne,
      petit:"à "+lieu(e1.de)+", à "+e1.heure_depart+".", rangs};
  if (t < a1)
    return {etat:"Dans "+art(e1.ligne)+e1.ligne, couleur:"var(--bleu)", badge:e1,
      gros:"Descends à "+lieu(e1.a), petit:"vers "+e1.heure_arrivee+".", rangs};
  if (e2 && t < d2)
    return {etat:"Attends là", couleur:"var(--ambre)", badge:e2,
      gros:"Prends "+art(e2.ligne)+e2.ligne,
      petit:"à "+lieu(e2.de)+", à "+e2.heure_depart+".", rangs};
  if (e2 && t < a2)
    return {etat:"Dans "+art(e2.ligne)+e2.ligne, couleur:"var(--bleu)", badge:e2,
      gros:"Descends à "+lieu(e2.a), petit:"vers "+e2.heure_arrivee+".", rangs};
  return {etat:"Presque arrivée", couleur:"var(--vert)", gros:"Marche "+but,
    petit:(plan.marche_fin_min||0)+" minutes à pied. Tu arrives à "+plan.arrivee+".", rangs};
}

function scene(){
  const r = etat.resume, t = maintenant();
  if (!r)
    /* Rien à afficher : soit c'est la première ouverture, soit le service
       ne répond pas. Dans les deux cas, elle n'a rien à faire — et surtout
       rien à réparer. On le dit avec des mots simples. */
    return etat.horsligne
      ? {etat:"Je n'ai pas les horaires", couleur:"var(--ambre)",
         gros:"Attends un peu", petit:"Ça revient tout seul.",
         basG:"nouvelle tentative en cours"}
      : {etat:"Un instant…", couleur:"var(--douce)", gros:" ", petit:""};

  const plan = etat.plan;
  /* Le sens du car affiché est celui du car, pas celui d'un repli passé :
     une fois arrivée au lycée le matin, c'est le retour qui se prépare. */
  const versEcole = r.sens === "aller";

  /* Le car est parti et son arrivée est encore devant : elle est très
     probablement dedans. Tant que son heure n'est pas dépassée d'un quart
     d'heure, c'est lui la réponse — le car scolaire passe avant tout. */
  const enR = r.en_route;
  const rate = r.car_saute || r.heure_car_manque || null;
  const doute = rate ? (t - mn(rate)) >= RETARD : false;
  const dedans = enR
    ? "dans le car : " + lieu(enR.destination) + " " + enR.heure_arrivee : "";

  /* Une route de secours en cours passe avant tout le reste. */
  if (plan && plan.etapes && plan.etapes.length){
    const fini = t >= mn(plan.arrivee);
    if (!fini || t < mn(plan.arrivee) + 20){
      if (fini)
        return {etat:plan.sens === "aller" ? "Tu es au lycée" : "Tu es rentrée",
          couleur:"var(--vert)", gros:"Tu es arrivée",
          petit:plan.sens === "aller" ? "Bonne journée." : "",
          basG:"Arrivée "+plan.arrivee};
      const s = etapesPlan(t, plan);
      s.basG = dedans || (r.car_manque ? "Le car n'est pas passé"
                                       : "Pas de car aujourd'hui");
      s.basD = "arrivée "+plan.arrivee;
      return s;
    }
  }

  if (enR && !doute)
    return {etat:"Dans le car", couleur:"var(--bleu)",
      gros:enR.sens === "aller" ? "Bonne journée" : "Bonne route",
      petit:"Tu descends à "+lieu(enR.destination)+" à "+enR.heure_arrivee+".",
      rangs:[{h:enR.heure, quoi:"Le car "+(enR.car||""), sous:lieu(enR.arret), actif:true},
             {h:enR.heure_arrivee, quoi:"Tu descends", sous:lieu(enR.destination), actif:false}],
      basG:"Car "+(enR.car||""), basD:r.fiche_officielle ? "horaires du lycée" : ""};

  if (!r.jour_de_classe)
    return {etat:"Pas de cours aujourd'hui", couleur:"var(--douce)",
      gros:"Rien à faire", petit:"", basG:"Ligne "+(r.ligne||"")};

  if (etat.fini.retour)
    return {etat:"Tu es rentrée", couleur:"var(--vert)", gros:"Rien à faire",
      petit:"", basG:"Ligne "+(r.ligne||"")};

  if (!r.heure_car)
    return {etat:"Pas de car aujourd'hui", couleur:"var(--douce)",
      gros:"Rien à faire", petit:"", basG:"Ligne "+(r.ligne||"")};

  /* Le car, de la maison au lycée ou du lycée à la maison. */
  /* Le service renvoie « 06:46 » ; une version plus ancienne renvoyait
     un horodatage complet. On accepte les deux. */
  const depart = String(r.heure_depart_a_pied || "").slice(-5);
  const car = mn(r.heure_car), arrivee = mn(r.heure_arrivee);
  const rangs = [
    {h:depart || "--:--", quoi:versEcole ? "Pars de la maison" : "Pars du lycée",
     sous:"jusqu'à "+lieu(r.arret), actif:depart && t>=mn(depart) && t<car},
    {h:r.heure_car, quoi:"Le car "+(r.car||""), sous:lieu(r.arret),
     actif:t>=car && (!arrivee || t<arrivee)},
    {h:r.heure_arrivee || "--:--", quoi:"Tu descends", sous:lieu(r.destination),
     actif:arrivee && t>=arrivee},
  ];
  const bas = ["Car "+(r.car||""), r.fiche_officielle ? "horaires du lycée" : ""];

  /* Un car sauté allonge le compte à rebours d'un coup : on dit pourquoi.
     Pendant un quart d'heure on ne l'accuse de rien — elle est peut-être
     dedans. */
  let entete = null;
  if (r.car_saute && depart && t < mn(depart)){
    /* On n'explique le car raté que tant qu'elle attend. Une fois en
       route, répéter la mauvaise nouvelle ne sert plus à rien. */
    const retard = t - mn(r.car_saute);
    entete = retard >= RETARD
      ? {etat:"Le car de "+r.car_saute+" n'est pas passé", couleur:"var(--rouge)"}
      : {etat:"Le car n'est pas là", couleur:"var(--ambre)"};
  }

  if (depart && t < mn(depart)){
    const s = entete
      ? {etat:entete.etat, couleur:entete.couleur,
         gros:"Le prochain car est à "+r.heure_car,
         petit:"Tu pars à "+depart+"."}
      : {etat:"Tu as le temps", couleur:"var(--ambre)",
         gros:versEcole ? "Reste à la maison" : "Reste au lycée",
         petit:"Tu pars à "+depart+"."};
    return Object.assign(s, {rangs, basG:dedans || bas[0], basD:bas[1]});
  }
  if (t < car)
    return {etat:entete ? entete.etat : "C'est le moment",
      couleur:entete ? entete.couleur : "var(--vert)",
      gros:"Pars maintenant", petit:"Le car est à "+r.heure_car+" à "+lieu(r.arret)+".",
      rangs, basG:bas[0], basD:bas[1]};
  if (!arrivee || t < arrivee)
    return {etat:"Dans le car", couleur:"var(--bleu)",
      gros:versEcole ? "Bonne journée" : "Bonne route",
      petit:"Tu descends à "+lieu(r.destination)+(r.heure_arrivee ? " à "+r.heure_arrivee : "")+".",
      rangs, basG:bas[0], basD:bas[1]};
  return {etat:versEcole ? "Tu es au lycée" : "Tu es rentrée", couleur:"var(--vert)",
    gros:"Tu es arrivée", petit:"", rangs:[], basG:"Arrivée "+r.heure_arrivee};
}

/* ------------------------------------------------------------------ */
function dessiner(){
  const s = scene();
  $("pendule").textContent = hhmm(new Date());
  $("ecran").style.setProperty("--etat", s.couleur || "var(--ambre)");
  $("etat").textContent = s.etat;
  $("gros").textContent = s.gros;
  $("petit").textContent = s.petit || "";
  const badge = $("badge");
  badge.replaceChildren();
  if (s.badge) {
    const b = el("span", "badge", s.badge.ligne);
    b.style.background = couleurSure(s.badge.couleur);
    badge.appendChild(b);
  }

  const rangs = s.rangs || [];
  $("horaire").hidden = rangs.length === 0;
  $("horaire").replaceChildren(...rangs.map(r => {
    const ligne = el("div", "rang " + (r.actif ? "actif" : "pale"));
    ligne.appendChild(el("span", "h", r.h));
    if (r.fil) {
      const f = el("span", "fil", r.fil);
      f.style.background = couleurSure(r.couleur);
      ligne.appendChild(f);
    } else {
      ligne.appendChild(el("span", "puce"));
    }
    const quoi = el("span", "quoi", r.quoi);
    quoi.appendChild(el("small", null, r.sous || ""));
    ligne.appendChild(quoi);
    return ligne;
  }));

  $("bas-g").textContent = s.basG || "";
  const bd = $("bas-d");
  bd.replaceChildren();
  if (etat.horsligne) {
    bd.appendChild(el("span", "vieux", "horaires de "
      + (etat.lu ? hhmm(new Date(etat.lu)) : "tout à l'heure")));
  } else {
    bd.textContent = s.basD || "";
  }
}

lire(); dessiner(); rafraichir();
/* L'heure avance toute seule ; les horaires se revoient chaque minute. */
setInterval(dessiner, 10000);
document.addEventListener("visibilitychange", () => {
  if (!document.hidden) rafraichir();
});
if ("serviceWorker" in navigator) {
  navigator.serviceWorker.register("sw.js").catch(() => {});
}
})();
</script>
