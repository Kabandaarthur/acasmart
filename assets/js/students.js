 // Student Form Functions
function toggleAddStudentForm() {
    const form = document.getElementById('addStudentForm');
    const btn = document.getElementById('addStudentBtn');
    const btnText = document.getElementById('btnText');
    
    if (form.classList.contains('hidden')) {
        form.classList.remove('hidden');
        form.classList.add('scale-100');
        btnText.textContent = '- Cancel';
        btn.classList.replace('bg-blue-500', 'bg-red-500');
        btn.classList.replace('hover:bg-blue-600', 'hover:bg-red-600');
        fetchAdmissionNumber();
    } else {
        form.classList.add('hidden');
        form.classList.remove('scale-100');
        btnText.textContent = '+ Add New Student';
        btn.classList.replace('bg-red-500', 'bg-blue-500');
        btn.classList.replace('hover:bg-red-600', 'hover:bg-blue-600');
        document.querySelector('form').reset();
    }
}

// Modal Functions
function openDetailsModal(student) {
    // ... existing openDetailsModal code ...
}

function closeDetailsModal() {
    // ... existing closeDetailsModal code ...
}

function openUpdateModal(student) {
    // ... existing openUpdateModal code ...
}

function closeUpdateModal() {
    // ... existing closeUpdateModal code ...
}

// Delete Functions
function deleteStudent(studentId, classId) {
    const modal = document.getElementById('deleteConfirmationModal');
    const confirmButton = document.getElementById('deleteConfirmButton');
    modal.classList.remove('hidden');
    modal.classList.add('fade-in');

    confirmButton.onclick = function() {
        const deleteUrl = `?delete=${studentId}&class_id=${classId}`;
        fetch(deleteUrl)
            .then(response => {
                if (response.ok) {
                    closeDeleteModal();
                    showNotification('Student deleted successfully', 'success');
                    setTimeout(() => {
                        window.location.reload();
                    }, 2000);
                } else {
                    throw new Error('Failed to delete student');
                }
            })
            .catch(error => {
                showNotification('Error deleting student', 'error');
            });
    };
}

function closeDeleteModal() {
    const modal = document.getElementById('deleteConfirmationModal');
    modal.classList.add('hidden');
    modal.classList.remove('fade-in');
}

// Utility Functions
function showNotification(message, type) {
    const notification = document.createElement('div');
    notification.className = `fixed top-4 right-4 px-6 py-3 rounded shadow-lg z-50 animate-fade-in-out ${
        type === 'success' ? 'bg-green-500' : 'bg-red-500'
    } text-white`;
    notification.textContent = message;
    document.body.appendChild(notification);
    setTimeout(() => {
        notification.remove();
    }, 3000);
}

function fetchAdmissionNumber() {
    fetch('get_next_admission.php')
        .then(response => response.text())
        .then(data => {
            document.getElementById('admission_number').value = data;
        })
        .catch(error => console.error('Error fetching admission number:', error));
}

// Event Listeners
document.addEventListener('DOMContentLoaded', function() {
    // Modal close on outside click
    document.getElementById('deleteConfirmationModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeDeleteModal();
    });

    document.getElementById('detailsModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeDetailsModal();
    });

    document.getElementById('updateModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeUpdateModal();
    });

    // Escape key listener
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            if (!document.getElementById('deleteConfirmationModal')?.classList.contains('hidden')) {
                closeDeleteModal();
            }
            if (!document.getElementById('detailsModal')?.classList.contains('hidden')) {
                closeDetailsModal();
            }
            if (!document.getElementById('updateModal')?.classList.contains('hidden')) {
                closeUpdateModal();
            }
        }
    });

    // Form validation
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function(e) {
            const requiredFields = form.querySelectorAll('[required]');
            let isValid = true;
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    isValid = false;
                    field.classList.add('border-red-500');
                } else {
                    field.classList.remove('border-red-500');
                }
            });
            
            if (!isValid) {
                e.preventDefault();
                alert('Please fill in all required fields.');
            }
        });
    });

    // Age input validation
    document.querySelectorAll('input[type="number"]').forEach(input => {
        input.addEventListener('input', function(e) {
            if (this.value < 0) this.value = 0;
            if (this.value > 100) this.value = 100;
        });
    });
});