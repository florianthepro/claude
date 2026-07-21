// Theme
function applyTheme(t){document.documentElement.setAttribute('data-theme',t);localStorage.setItem('nx_theme',t);}
(function(){const t=localStorage.getItem('nx_theme');if(t)applyTheme(t);})();
function toggleTheme(){const c=document.documentElement.getAttribute('data-theme')||'dark';const n=c==='dark'?'light':'dark';applyTheme(n);fetch('?action=save_theme&theme='+n);}

// Sidebar (mobile)
function toggleMenu(){document.querySelector('.sidebar')?.classList.toggle('open');document.querySelector('.backdrop')?.classList.toggle('open');}

// Modals
function openModal(id){document.getElementById(id)?.classList.add('open');}
function closeModal(id){if(id){document.getElementById(id)?.classList.remove('open');}else{document.querySelectorAll('.modal.open').forEach(m=>m.classList.remove('open'));}}
document.addEventListener('click',e=>{if(e.target.classList.contains('modal'))e.target.classList.remove('open');});
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeModal();});

// Notes
function newNote(){const f=document.getElementById('noteForm');if(!f)return;f.reset();f.id.value='';document.getElementById('noteModalTitle').textContent='Neue Notiz';openModal('noteModal');}
function editNote(el){const d=el.dataset,f=document.getElementById('noteForm');openModal('noteModal');f.id.value=d.id;f.title.value=d.title;f.body.value=d.body;f.querySelector('[name=color][value="'+d.color+'"]')?.click();document.getElementById('noteModalTitle').textContent='Notiz bearbeiten';}

// Calendar
function openDay(day){const f=document.getElementById('evForm');if(!f)return;f.reset();f.id.value='';f.day.value=day;document.getElementById('evModalTitle').textContent='Termin am '+day;openModal('evModal');}
function editEvent(el){event.stopPropagation();const d=el.dataset,f=document.getElementById('evForm');f.id.value=d.id;f.title.value=d.title;f.day.value=d.day;f.time.value=d.time;f.end_time.value=d.end||'';f.description.value=d.desc;f.querySelector('[name=color][value="'+d.color+'"]')?.click();document.getElementById('evModalTitle').textContent='Termin bearbeiten';openModal('evModal');}

// Contacts
function newContact(){const f=document.getElementById('contactForm');if(!f)return;f.reset();f.id.value='';document.getElementById('contactModalTitle').textContent='Neuer Kontakt';openModal('contactModal');}
function editContact(el){const d=el.dataset,f=document.getElementById('contactForm');f.id.value=d.id;f.name.value=d.name;f.email.value=d.email;f.phone.value=d.phone;f.note.value=d.note;document.getElementById('contactModalTitle').textContent='Kontakt bearbeiten';openModal('contactModal');}

// Admin quota
function quotaModal(id,name,gb){const f=document.getElementById('quotaForm');if(!f)return;f.uid.value=id;f.gb.value=gb;document.getElementById('quotaUser').textContent=name;openModal('quotaModal');}

// Files drag & drop
function initDrop(){const dz=document.getElementById('dropzone');if(!dz)return;const inp=document.getElementById('fileInput');
  ['dragenter','dragover'].forEach(ev=>dz.addEventListener(ev,e=>{e.preventDefault();dz.classList.add('drag');}));
  ['dragleave','drop'].forEach(ev=>dz.addEventListener(ev,e=>{e.preventDefault();dz.classList.remove('drag');}));
  dz.addEventListener('drop',e=>{inp.files=e.dataTransfer.files;document.getElementById('uploadForm').submit();});
  dz.addEventListener('click',()=>inp.click());
  inp.addEventListener('change',()=>document.getElementById('uploadForm').submit());}
document.addEventListener('DOMContentLoaded',initDrop);
