// Paginação do Kanban
// Controla a navegação entre páginas de cards quando há mais de 10 cards em uma coluna

const kanbanPages = {};

function paginateKanban(columnId, direction) {
    // Inicializar página se não existir
    if (!kanbanPages[columnId]) {
        kanbanPages[columnId] = 0;
    }
    
    // Encontrar coluna
    const column = document.querySelector(`.kanbanCol[data-column-id="${columnId}"]`);
    if (!column) return;
    
    const totalItems = parseInt(column.dataset.totalItems || 0);
    const itemsPerPage = parseInt(column.dataset.itemsPerPage || 10);
    const totalPages = Math.ceil(totalItems / itemsPerPage);
    
    // Calcular nova página
    let newPage = kanbanPages[columnId] + direction;
    if (newPage < 0) newPage = 0;
    if (newPage >= totalPages) newPage = totalPages - 1;
    
    kanbanPages[columnId] = newPage;
    
    // Esconder todos os cards
    const cards = column.querySelectorAll('.kanbanCard');
    cards.forEach(card => {
        card.style.display = 'none';
    });
    
    // Mostrar apenas cards da página atual
    cards.forEach(card => {
        const pageIndex = parseInt(card.dataset.pageIndex || 0);
        if (pageIndex === newPage) {
            card.style.display = '';
        }
    });
    
    // Atualizar UI de paginação
    const currentPageSpan = column.querySelector('.kanbanCurrentPage');
    if (currentPageSpan) {
        currentPageSpan.textContent = newPage + 1;
    }
    
    // Atualizar estado dos botões
    const prevBtn = column.querySelector('.kanbanPaginationPrev');
    const nextBtn = column.querySelector('.kanbanPaginationNext');
    
    if (prevBtn) {
        prevBtn.disabled = newPage === 0;
    }
    
    if (nextBtn) {
        nextBtn.disabled = newPage === totalPages - 1;
    }
}

// Inicializar paginação ao carregar página
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.kanbanCol').forEach(column => {
        const columnId = column.dataset.columnId;
        if (columnId) {
            kanbanPages[columnId] = 0;
        }
    });
});
