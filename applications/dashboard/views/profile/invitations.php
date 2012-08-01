<?php if (!defined('APPLICATION')) exit(); ?>
<h2 class="H"><?php echo T('Invitations'); ?></h2>
<div class="FormWrapper">
<?php
echo $this->Form->Open();
echo $this->Form->Errors();
if ($this->InvitationCount > 0) {
   echo '<div class="Info">'.sprintf(T('You have %s invitations left for this month.'), $this->InvitationCount).'</div>';
}
if ($this->InvitationCount != 0) {
?>
   <p><?php echo T('CallToInvite', 'Know someone who should join us?'); ?></p>
<ul>
   <li>
   <?php
      echo $this->Form->Label('Email', 'Email');
      echo $this->Form->TextBox('Email');
      echo ' ', $this->Form->Button('Send Invitation');
   ?></li>
</ul>
<?php
}

if ($this->InvitationData->NumRows() > 0) {
?>
<table class="AltRows PendingInvitations">
   <thead>
      <tr>
         <th class="Alt"><?php echo T('Sent To'); ?></th>
         <th><?php echo T('On'); ?></th>
         <th><?php echo T('Invitation Code', 'Code'); ?></th>
         <th class="Alt"><?php echo T('Status'); ?></th>
      </tr>
   </thead>
   <tbody>
<?php
$Session = Gdn::Session();
$Alt = FALSE;
foreach ($this->InvitationData->Format('Text')->Result() as $Invitation) {
   $Alt = $Alt == TRUE ? FALSE : TRUE;
?>
   <tr<?php echo ($Alt ? ' class="Alt"' : ''); ?>>
      <td class="Alt"><?php
         if ($Invitation->AcceptedName == '')
            echo $Invitation->Email;
         else
            echo Anchor($Invitation->AcceptedName, '/profile/'.$Invitation->AcceptedUserID);         
      ?></td>
      <td><?php echo Gdn_Format::Date($Invitation->DateInserted); ?></td>
      <td><?php echo $Invitation->Code; ?></td>
      <td class="Alt"><?php
         if ($Invitation->AcceptedName == '') {
            echo T('Pending');
            echo '<div>'
               .Anchor(T('Uninvite'), '/profile/uninvite/'.$Invitation->InvitationID.'/'.$Session->TransientKey(), 'Uninvite')
               .' | '.Anchor(T('Send Again'), '/profile/sendinvite/'.$Invitation->InvitationID.'/'.$Session->TransientKey(), 'SendAgain')
            .'</div>';
         } else {
            echo T('Accepted');
         }
            
      ?></td>
   </tr>
<?php } ?>
    </tbody>
</table>
<?php
}
echo $this->Form->Close();
?></div>