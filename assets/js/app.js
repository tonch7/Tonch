/**
 * Tonch — JavaScript
 * Criado por Gabriel Perdigao
 * WWW.TONCH.COM.BR
 * DEUS SEJA LOUVADO
 */

// ---- MODAIS ----
function openModal(id) {
  var m = document.getElementById(id);
  if (m) m.classList.add('open');
}
function closeModal(id) {
  var m = document.getElementById(id);
  if (m) m.classList.remove('open');
}
function initModals() {
  document.querySelectorAll('.modal-bg').forEach(function(m) {
    m.addEventListener('click', function(e) {
      if (e.target === m) m.classList.remove('open');
    });
  });
  document.querySelectorAll('.modal-close').forEach(function(b) {
    b.addEventListener('click', function() {
      b.closest('.modal-bg').classList.remove('open');
    });
  });
}

// ---- FLASH — PERSISTENTE, CLIQUE NO × PARA FECHAR ----
function initFlash() {
  document.querySelectorAll('.alert-flash').forEach(function(a) {
    // Fecha ao clicar no botão × ou na mensagem inteira
    a.addEventListener('click', function(e) {
      a.style.transition = 'opacity .3s, transform .3s';
      a.style.opacity = '0';
      a.style.transform = 'translateY(-8px)';
      setTimeout(function() {
        var container = a.closest('.flash-container');
        a.remove();
        if (container && !container.querySelector('.alert')) container.remove();
      }, 320);
    });
  });
}

// ---- MÁSCARA MONETÁRIA ----
function maskMoney(el) {
  el.addEventListener('input', function() {
    var v = this.value.replace(/\D/g, '');
    if (!v) { this.value = ''; return; }
    v = (parseInt(v, 10) / 100).toFixed(2);
    this.value = parseFloat(v).toLocaleString('pt-BR', {
      minimumFractionDigits: 2, maximumFractionDigits: 2
    });
  });
}
function initMoneyInputs() {
  document.querySelectorAll('.money-input').forEach(maskMoney);
}

function parseBR(s) {
  if (!s) return 0;
  return parseFloat(s.replace(/\./g, '').replace(',', '.')) || 0;
}

// ---- CÁLCULO DE TAXA (formulário de lançamento) ----
function calcTaxa() {
  var bruto  = parseBR(document.getElementById('valor') ? document.getElementById('valor').value : '0');
  var tipo   = document.getElementById('taxa_tipo')  ? document.getElementById('taxa_tipo').value  : 'fixo';
  var txVal  = parseBR(document.getElementById('taxa_valor_input') ? document.getElementById('taxa_valor_input').value : '0');
  var taxa   = tipo === 'percentual' ? bruto * txVal / 100 : txVal;
  var liq    = bruto - taxa;

  var elTaxa = document.getElementById('taxa_calculada');
  var elLiq  = document.getElementById('valor_liquido_show');
  if (elTaxa) elTaxa.textContent = 'R$ ' + taxa.toLocaleString('pt-BR',{minimumFractionDigits:2});
  if (elLiq)  elLiq.textContent  = 'R$ ' + liq.toLocaleString('pt-BR',{minimumFractionDigits:2});

  var hidTaxa = document.getElementById('taxa_valor');
  if (hidTaxa) hidTaxa.value = taxa.toFixed(2);
}

// ---- TOGGLE TAXA FIELDS ----
function toggleTaxaFields() {
  var metodoSel = document.getElementById('metodo_id');
  var tipoLanc  = document.getElementById('tipo') ? document.getElementById('tipo').value : 'entrada';
  if (!metodoSel) return;
  var selected  = metodoSel.options[metodoSel.selectedIndex];
  // REGRA: taxa só se aplica em ENTRADAS (recebimentos com cartão, boleto, pix máquina)
  // Saídas não têm dedução de taxa — o valor já é o valor cheio pago
  var temTaxa   = (tipoLanc === 'entrada') && selected && selected.dataset.temTaxa === '1';
  var taxaBox   = document.getElementById('taxa-box');
  if (taxaBox) taxaBox.style.display = temTaxa ? 'flex' : 'none';

  if (temTaxa && selected) {
    var tipoEl = document.getElementById('taxa_tipo');
    var valEl  = document.getElementById('taxa_valor_input');
    if (tipoEl) tipoEl.value = selected.dataset.taxaTipo || 'fixo';
    if (valEl)  valEl.value  = parseFloat(selected.dataset.taxaValor || '0').toLocaleString('pt-BR',{minimumFractionDigits:2});
    calcTaxa();
  } else {
    // Zera taxa se saída ou sem taxa
    var hidTaxa = document.getElementById('taxa_valor');
    if (hidTaxa) hidTaxa.value = '0';
  }
}

// ---- TIPO DE LANÇAMENTO: mostra/oculta campos NF e taxa ----
function toggleNFFields() {
  var tipo  = document.getElementById('tipo') ? document.getElementById('tipo').value : '';
  var nfBox = document.getElementById('nf-box');
  if (nfBox) nfBox.style.display = tipo === 'entrada' ? 'flex' : 'none';

  // Transferência: mostra conta_destino
  var trfBox = document.getElementById('trf-box');
  if (trfBox) trfBox.style.display = tipo === 'transferencia' ? 'flex' : 'none';

  // Transferência: oculta método
  var metodoBox = document.getElementById('metodo-box');
  if (metodoBox) metodoBox.style.display = tipo === 'transferencia' ? 'none' : 'flex';

  // Recalcula campos de taxa (saída → sempre oculta taxa)
  toggleTaxaFields();
}

// ---- CONFIRMAÇÃO DE EXCLUSÃO ----
function confirmDel(url, msg) {
  if (confirm(msg || 'Confirmar exclusão?')) window.location.href = url;
}

// ---- CHART: Evolução mensal ----
function renderEvolucaoChart(canvasId, labels, entradas, saidas, saldos) {
  var ctx = document.getElementById(canvasId);
  if (!ctx) return;
  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [
        { label: 'Entradas', data: entradas, backgroundColor: 'rgba(0,119,0,.7)', borderColor: '#007700', borderWidth: 1 },
        { label: 'Saídas',   data: saidas,   backgroundColor: 'rgba(204,0,0,.7)',  borderColor: '#cc0000', borderWidth: 1 },
        { label: 'Saldo',    data: saldos,   type: 'line', borderColor: '#0066cc',
          backgroundColor: 'rgba(0,102,204,.08)', borderWidth: 2, pointRadius: 3, fill: true, tension: .3, yAxisID: 'y' }
      ]
    },
    options: {
      responsive: true,
      plugins: {
        legend: { position: 'bottom', labels: { font: { size: 11 } } },
        tooltip: { callbacks: { label: function(c) {
          return c.dataset.label + ': R$ ' +
            parseFloat(c.raw).toLocaleString('pt-BR', {minimumFractionDigits:2});
        }}}
      },
      scales: {
        y: { ticks: { callback: function(v) {
          return 'R$' + (v/1000).toLocaleString('pt-BR',{minimumFractionDigits:0}) + 'k';
        }, font:{size:10} } },
        x: { ticks: { font:{size:10} } }
      }
    }
  });
}

// ---- CHART: Doughnut ranking ----
function renderDoughnut(canvasId, labels, data) {
  var ctx = document.getElementById(canvasId);
  if (!ctx || !data.length) return;
  new Chart(ctx, {
    type: 'doughnut',
    data: {
      labels: labels,
      datasets: [{ data: data,
        backgroundColor: ['#cc0000','#ff6600','#cc8800','#888800','#007700'],
        borderWidth: 1 }]
    },
    options: {
      responsive: true,
      plugins: {
        legend: { position: 'right', labels: { font:{size:11}, boxWidth:14 } },
        tooltip: { callbacks: { label: function(c) {
          return c.label+': R$ '+parseFloat(c.raw).toLocaleString('pt-BR',{minimumFractionDigits:2});
        }}}
      }
    }
  });
}

// ---- INIT ----
document.addEventListener('DOMContentLoaded', function() {
  initModals();
  initFlash();
  initMoneyInputs();

  var tipoSel = document.getElementById('tipo');
  if (tipoSel) { tipoSel.addEventListener('change', toggleNFFields); toggleNFFields(); }

  var metodoSel = document.getElementById('metodo_id');
  if (metodoSel) { metodoSel.addEventListener('change', toggleTaxaFields); toggleTaxaFields(); }

  var taxaTipoSel = document.getElementById('taxa_tipo');
  if (taxaTipoSel) taxaTipoSel.addEventListener('change', calcTaxa);

  var taxaValInput = document.getElementById('taxa_valor_input');
  if (taxaValInput) taxaValInput.addEventListener('input', calcTaxa);

  var valorInput = document.getElementById('valor');
  if (valorInput) valorInput.addEventListener('input', calcTaxa);
});
