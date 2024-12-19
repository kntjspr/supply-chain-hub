// Initialize tooltips
document.addEventListener('DOMContentLoaded', function() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});

// Show loading spinner
function showSpinner() {
    const spinner = document.getElementById('loadingSpinner');
    if (spinner) {
        spinner.style.display = 'flex';
    }
}

// Hide loading spinner
function hideSpinner() {
    const spinner = document.getElementById('loadingSpinner');
    if (spinner) {
        spinner.style.display = 'none';
    }
}

// Format currency
function formatCurrency(amount) {
    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP'
    }).format(amount);
}

// Format date
function formatDate(dateString) {
    const options = { 
        year: 'numeric', 
        month: 'long', 
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    };
    return new Date(dateString).toLocaleDateString('en-US', options);
}

// Handle form submission with AJAX
function submitFormAjax(formElement, successCallback, errorCallback) {
    formElement.addEventListener('submit', function(e) {
        e.preventDefault();
        showSpinner();

        const formData = new FormData(this);
        
        fetch(this.action, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            hideSpinner();
            if (data.success) {
                if (successCallback) successCallback(data);
            } else {
                if (errorCallback) errorCallback(data);
                else alert(data.message || 'An error occurred');
            }
        })
        .catch(error => {
            hideSpinner();
            console.error('Error:', error);
            if (errorCallback) errorCallback({message: 'An error occurred'});
            else alert('An error occurred');
        });
    });
}

// Handle delete confirmation
function confirmDelete(message = 'Are you sure you want to delete this item?') {
    return confirm(message);
}

// Handle AJAX requests
function makeAjaxRequest(url, method = 'GET', data = null, successCallback = null, errorCallback = null) {
    showSpinner();

    const options = {
        method: method,
        headers: {
            'Content-Type': 'application/json'
        }
    };

    if (data && method !== 'GET') {
        options.body = JSON.stringify(data);
    }

    fetch(url, options)
        .then(response => response.json())
        .then(data => {
            hideSpinner();
            if (data.success) {
                if (successCallback) successCallback(data);
            } else {
                if (errorCallback) errorCallback(data);
                else alert(data.message || 'An error occurred');
            }
        })
        .catch(error => {
            hideSpinner();
            console.error('Error:', error);
            if (errorCallback) errorCallback({message: 'An error occurred'});
            else alert('An error occurred');
        });
}

// Update table row
function updateTableRow(tableId, rowId, data) {
    const table = document.getElementById(tableId);
    if (!table) return;

    const row = table.querySelector(`tr[data-id="${rowId}"]`);
    if (!row) return;

    Object.keys(data).forEach(key => {
        const cell = row.querySelector(`td[data-field="${key}"]`);
        if (cell) {
            cell.textContent = data[key];
        }
    });
}

// Add table row
function addTableRow(tableId, data) {
    const table = document.getElementById(tableId);
    if (!table) return;

    const tbody = table.querySelector('tbody');
    if (!tbody) return;

    const row = document.createElement('tr');
    row.setAttribute('data-id', data.id);

    Object.keys(data).forEach(key => {
        const cell = document.createElement('td');
        cell.setAttribute('data-field', key);
        cell.textContent = data[key];
        row.appendChild(cell);
    });

    tbody.insertBefore(row, tbody.firstChild);
}

// Delete table row
function deleteTableRow(tableId, rowId) {
    const table = document.getElementById(tableId);
    if (!table) return;

    const row = table.querySelector(`tr[data-id="${rowId}"]`);
    if (row) {
        row.remove();
    }
}

// Handle modal forms
function initializeModalForm(modalId, formId, successCallback, errorCallback) {
    const modal = document.getElementById(modalId);
    const form = document.getElementById(formId);
    
    if (!modal || !form) return;

    const modalInstance = new bootstrap.Modal(modal);

    submitFormAjax(form, 
        (data) => {
            modalInstance.hide();
            if (successCallback) successCallback(data);
        },
        (error) => {
            if (errorCallback) errorCallback(error);
        }
    );
}

// Initialize DataTables
function initializeDataTable(tableId, options = {}) {
    const defaultOptions = {
        pageLength: 10,
        responsive: true,
        language: {
            search: "Search:",
            lengthMenu: "Show _MENU_ entries",
            info: "Showing _START_ to _END_ of _TOTAL_ entries",
            paginate: {
                first: "First",
                last: "Last",
                next: "Next",
                previous: "Previous"
            }
        }
    };

    const mergedOptions = { ...defaultOptions, ...options };
    return new DataTable(`#${tableId}`, mergedOptions);
}

// Handle file upload preview
function handleFileUploadPreview(inputId, previewId) {
    const input = document.getElementById(inputId);
    const preview = document.getElementById(previewId);
    
    if (!input || !preview) return;

    input.addEventListener('change', function() {
        if (this.files && this.files[0]) {
            const reader = new FileReader();
            
            reader.onload = function(e) {
                preview.src = e.target.result;
                preview.style.display = 'block';
            }
            
            reader.readAsDataURL(this.files[0]);
        }
    });
}

// Export table to CSV
function exportTableToCSV(tableId, filename) {
    const table = document.getElementById(tableId);
    if (!table) return;

    let csv = [];
    const rows = table.querySelectorAll('tr');
    
    for (let i = 0; i < rows.length; i++) {
        const row = [], cols = rows[i].querySelectorAll('td, th');
        
        for (let j = 0; j < cols.length; j++) {
            let data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, '').replace(/(\s\s)/gm, ' ');
            data = data.replace(/"/g, '""');
            row.push('"' + data + '"');
        }
        
        csv.push(row.join(','));
    }

    const csvFile = new Blob([csv.join('\n')], { type: 'text/csv' });
    const downloadLink = document.createElement('a');
    
    downloadLink.download = filename;
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = 'none';
    
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
} 