<?php

class MigK2Config
{
	/* 
	 * Super admin account is needed to create Joomla content . 
	 * $migk2Username: Super admin username
	 * $migk2Password: Super admin password
	*/
	public $migk2Username = '';
	public $migk2Password = '';

	/* Attachment field id */
	public $attachmentCFId = '';

	/* K2 items migrated per loop, just to avoid timeout */
	public $itemsPerLoop = 50;
	
	/* The mapping between K2 extra fields and Articles custom fields */
	public $cfMapping = [
// 'k2_field_id' => 'content_field_id',
		'1' 	=> '5',
		'2' 	=> '6',
	];
}
