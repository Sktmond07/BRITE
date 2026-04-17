<?php
// equipment_modal_content.php - Modal HTML content for equipment management
?>

<!-- Equipment Modal -->
<div id="equipmentModal" class="modal">
  <div class="modal-content" style="max-width: 600px;">
    <div class="modal-header">
      <h2 id="equipmentModalTitle"><i class="fas fa-tools"></i> Equipment</h2>
      <button class="close-modal" onclick="closeEquipmentModal()">&times;</button>
    </div>
    <div class="modal-body" id="equipmentModalBody">
      <!-- Dynamic content -->
    </div>
  </div>
</div>

<!-- Booking Details Modal -->
<div id="bookingModal" class="modal">
  <div class="modal-content" style="max-width: 700px;">
    <div class="modal-header">
      <h2><i class="fas fa-calendar-check"></i> Booking Details</h2>
      <button class="close-modal" onclick="closeBookingModal()">&times;</button>
    </div>
    <div class="modal-body" id="bookingModalBody">
      <!-- Dynamic content -->
    </div>
    <div class="modal-footer">
      <button class="btn-close" onclick="closeBookingModal()">Close</button>
    </div>
  </div>
</div>

<!-- Approval Modal for Bookings -->
<div id="bookingApprovalModal" class="modal">
  <div class="modal-content approval-modal">
    <div class="modal-header">
      <h2><i class="fas fa-check-circle"></i> Process Booking</h2>
      <button class="close-modal" onclick="closeBookingApprovalModal()">&times;</button>
    </div>
    <div class="modal-body">
      <div class="notification-icon warning" style="text-align:center;"><i class="fas fa-pen-alt"></i></div>
      <div class="notification-title" style="text-align:center;" id="bookingActionTitle">Approve Booking</div>
      <div class="notification-message" style="text-align:center;">Add optional notes for this transaction.</div>
      <textarea id="bookingApprovalNotes" class="approval-notes" rows="4" placeholder="Enter notes... (Optional)"></textarea>
      <div class="verify-buttons">
        <button class="btn-confirm" id="confirmBookingBtn" onclick="submitBookingAction()"><i class="fas fa-check-circle"></i> Confirm</button>
        <button class="btn-cancel" onclick="closeBookingApprovalModal()"><i class="fas fa-times"></i> Cancel</button>
      </div>
      <div id="bookingApprovalStatus" class="email-status" style="display:none;"></div>
    </div>
  </div>
</div>

<!-- Return Modal -->
<div id="returnModal" class="modal">
  <div class="modal-content approval-modal">
    <div class="modal-header">
      <h2><i class="fas fa-undo-alt"></i> Mark as Returned</h2>
      <button class="close-modal" onclick="closeReturnModal()">&times;</button>
    </div>
    <div class="modal-body">
      <div class="notification-icon success" style="text-align:center;"><i class="fas fa-box-open"></i></div>
      <div class="notification-title" style="text-align:center;">Confirm Return</div>
      <div class="notification-message" style="text-align:center;">Mark this item as returned by the resident.</div>
      <textarea id="returnNotes" class="approval-notes" rows="3" placeholder="Condition notes... (Optional)"></textarea>
      <div class="verify-buttons">
        <button class="btn-confirm" onclick="confirmReturn()"><i class="fas fa-check-circle"></i> Confirm Return</button>
        <button class="btn-cancel" onclick="closeReturnModal()"><i class="fas fa-times"></i> Cancel</button>
      </div>
    </div>
  </div>
</div>