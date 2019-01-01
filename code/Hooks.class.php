<?php

namespace FormTools\Modules\FieldTypeFile;

use FormTools\Core;
use FormTools\FieldTypes;
use FormTools\Fields;
use FormTools\Files;
use FormTools\General;
use FormTools\Hooks as CoreHooks;
use FormTools\Submissions;
use PDO, Exception;


/**
 * Contains all the code executed on the various core hooks. See Module.class.php to show what methods get called for
 * which hook.
 */
class Hooks
{

	/**
	 * Our template hook. This includes all required JS for the Edit Submission page.
	 * @param $curr_page
	 * @param $L
	 */
	public static function includeJs($curr_page, $L)
	{
		$root_url = Core::getRootUrl();
		$LANG = Core::$L;

		if ($curr_page != "admin_edit_submission" && $curr_page != "client_edit_submission") {
			return;
		}
		echo <<<END
<script src="$root_url/modules/field_type_file/scripts/edit_submission.js"></script>
<script>
if (typeof g.messages == 'undefined') {
	g.messages = {};
}

g.messages["confirm_delete_submission_file"] = "{$LANG["confirm_delete_submission_file"]}";
g.messages["confirm_delete_submission_files"] = "{$L["confirm_delete_submission_files"]}";
g.messages["phrase_please_confirm"] = "{$LANG["phrase_please_confirm"]}";
g.messages["word_yes"] = "{$LANG["word_yes"]}";
g.messages["word_no"] = "{$LANG["word_no"]}";
</script>
END;
	}


	/**
	 * Used for any module (e.g. Form Builder) that uses the form fields in a standalone context.
	 * @param $L
	 */
	public static function includeStandaloneJs($L)
	{
		$root_url = Core::getRootUrl();
		$LANG = Core::$L;

		// this includes the necessary JS for the file upload field type
		echo <<< END
  <script src="$root_url/modules/field_type_file/scripts/standalone.js"></script>
  <script>
  if (typeof g.messages == 'undefined')
    g.messages = {};

  g.messages["confirm_delete_submission_file"] = "{$LANG["confirm_delete_submission_file"]}";
  g.messages["confirm_delete_submission_files"] = "{$L["confirm_delete_submission_files"]}";
  g.messages["phrase_please_confirm"] = "{$LANG["phrase_please_confirm"]}";
  g.messages["word_yes"] = "{$LANG["word_yes"]}";
  g.messages["word_no"] = "{$LANG["word_no"]}";
  </script>
END;
	}


	/**
	 * Called by the Submissions::processFormSubmission function. It handles the file upload for all "File" Field types.
	 * @param $params
	 * @return array
	 */
	public static function processFormSubmissionHook($params)
	{
		$LANG = Core::$L;

		$file_fields = $params["file_fields"];
		if (empty($file_fields)) {
			return array(true, "");
		}

		$form_id = $params["form_id"];
		$submission_id = $params["submission_id"];

		$module_field_type_id = FieldTypes::getFieldTypeIdByIdentifier("file");
		$problem_files = array();
		$redirect_query_params = $params["redirect_query_params"];

		$return_info = array(
			"success" => true,
			"message" => "",
			"redirect_query_params" => $redirect_query_params
		);

		foreach ($file_fields as $file_field_info) {
			$field_id = $file_field_info["field_info"]["field_id"];
			$field_type_id = $file_field_info["field_info"]["field_type_id"];
			$field_name = $file_field_info["field_info"]["field_name"];
			$include_on_redirect = $file_field_info["field_info"]["include_on_redirect"];

			if ($module_field_type_id != $field_type_id) {
				continue;
			}

			$field_settings = Fields::getFieldSettings($field_id);
			$file_field_info["settings"] = $field_settings;

			// nothing was included in this field, just ignore it
			if (empty($_FILES[$field_name]["name"])) {
				continue;
			}

			list($success, $message, $filename) = self::uploadSubmissionFile($form_id, $submission_id, $file_field_info);
			if (!$success) {
				$problem_files[] = array($_FILES[$field_name]["name"], $message);
			} else {
				$return_info["message"] = $message;
				if ($include_on_redirect == "yes") {
					$redirect_query_params[] = "$field_name=" . rawurlencode($filename);
				}
			}
		}

		if (!empty($problem_files)) {
			$message = $LANG["notify_submission_updated_file_problems"] . "<br /><br />";
			foreach ($problem_files as $problem) {
				$message .= "&bull; <b>{$problem[0]}</b>: $problem[1]<br />\n";
			}

			$return_info = array(
				"success" => false,
				"message" => $message,
				"redirect_query_params" => $redirect_query_params
			);
		} else {
			$return_info["redirect_query_params"] = $redirect_query_params;
		}

		return $return_info;
	}


	/**
	 * This is called by the ft_process_form function. It handles the file upload for all "File" Field types.
	 * @param $params
	 * @return array
	 */
	public static function apiProcessFormSubmissionHook($params)
	{
		$LANG = Core::$L;

		// if the form being submitted doesn't contain any form fields we do nothing
		$file_fields = $params["file_fields"];
		if (empty($file_fields)) {
			return array(true, "");
		}

		$form_id = $params["form_id"];
		$submission_id = $params["submission_id"];
		$namespace = $params["namespace"];

		$module_field_type_id = FieldTypes::getFieldTypeIdByIdentifier("file");
		$problem_files = array();

		$return_info = array(
			"success" => true,
			"message" => ""
		);

		foreach ($file_fields as $file_field_info) {
			$field_type_id = $file_field_info["field_info"]["field_type_id"];
			if ($module_field_type_id != $field_type_id) {
				continue;
			}

			$field_id = $file_field_info["field_info"]["field_id"];
			$field_name = $file_field_info["field_info"]["field_name"];
			$field_settings = Fields::getFieldSettings($field_id);
			$file_field_info["settings"] = $field_settings;

			// nothing was included in this field, just ignore it
			if (empty($_FILES[$field_name]["name"])) {
				continue;
			}

			list($success, $message, $filename) = self::uploadSubmissionFile($form_id, $submission_id, $file_field_info);
			if (!$success) {
				$problem_files[] = array($_FILES[$field_name]["name"], $message);
			} else {
				$return_info["message"] = $message;
				$curr_file_info = array(
					"filename" => $filename,
					"file_upload_dir" => $file_field_info["settings"]["folder_path"],
					"file_upload_url" => $file_field_info["settings"]["folder_url"]
				);
				$_SESSION[$namespace][$field_name] = $curr_file_info;
			}
		}

		if (!empty($problem_files)) {
			$message = $LANG["notify_submission_updated_file_problems"] . "<br /><br />";
			foreach ($problem_files as $problem) {
				$message .= "&bull; <b>{$problem[0]}</b>: $problem[1]<br />\n";
			}

			$return_info = array(
				"success" => false,
				"message" => $message
			);
		}

		return $return_info;
	}

	/**
	 * Called whenever a submission or submissions are deleted. It's the hook for the ft_delete_submission_files
	 * Core function.
	 * @param $params
	 * @param $L
	 * @return array
	 */
	public static function deleteSubmissionHook($params, $L)
	{
		$file_field_info = $params["file_field_info"];

		$problems = array();
		$module_field_type_id = FieldTypes::getFieldTypeIdByIdentifier("file");
		foreach ($file_field_info as $info) {
			if ($info["field_type_id"] != $module_field_type_id) {
				continue;
			}

			$field_id = $info["field_id"];
			$filename = $info["filename"];

			$field_settings = Fields::getFieldSettings($field_id);
			$folder = $field_settings["folder_path"];

			if (!@unlink("$folder/$filename")) {
				if (!is_file("$folder/$filename")) {
					$problems[] = array(
						"filename" => $filename,
						"error" => General::evalSmartyString($L["notify_file_not_deleted_no_exist"], array("folder" => $folder))
					);
				} else {
					if (is_file("$folder/$filename") && (!is_readable("$folder/$filename") || !is_writable("$folder/$filename"))) {
						$problems[] = array(
							"filename" => $filename,
							"error" => General::evalSmartyString($L["notify_file_not_deleted_permissions"], array("folder" => $folder))
						);
					} else {
						$problems[] = array(
							"filename" => $filename,
							"error" => General::evalSmartyString($L["notify_file_not_deleted_unknown_error"], array("folder" => $folder))
						);
					}
				}
			}
		}

		if (empty($problems)) {
			return array(true, "");
		} else {
			return array(false, $problems);
		}
	}


	/**
	 * This is the hook for the Files::getUploadedFiles core function. It returns an array of hashes.
	 * @param $params
	 * @return array
	 */
	public static function getUploadedFilesHook($params)
	{
		$db = Core::$db;

		$form_id = $params["form_id"];
		$field_ids = (isset($params["field_ids"]) && is_array($params["field_ids"])) ? $params["field_ids"] : array();

		$module_field_type_id = FieldTypes::getFieldTypeIdByIdentifier("file");

		$data = array();
		foreach ($field_ids as $field_id) {
			$field_type_id = FieldTypes::getFieldTypeIdByFieldId($field_id);
			if ($field_type_id != $module_field_type_id) {
				continue;
			}

			$result = Fields::getFieldColByFieldId($form_id, $field_id);
			$col_name = $result[$field_id];
			if (empty($col_name)) {
				continue;
			}

			try {
				$db->query("SELECT submission_id, $col_name FROM {PREFIX}form_{$form_id}");
				$db->execute();
			} catch (Exception $e) {
				continue;
			}

			$field_settings = Fields::getFieldSettings($field_id);
			foreach ($field_settings as $row) {
				// here, nothing's been uploaded in the field
				if (empty($row[$col_name])) {
					continue;
				}

				$data[] = array(
					"submission_id" => $row["submission_id"],
					"field_id" => $field_id,
					"field_type_id" => $module_field_type_id,
					"folder_path" => $field_settings["folder_path"],
					"folder_url" => $field_settings["folder_url"],
					"filename" => $row[$col_name]
				);
			}
		}

		return array(
			"uploaded_files" => $data
		);
	}


	/**
	 * Handles the work for uploading ALL files across all fields in the form. Called by Submissions::updateSubmission().
	 * @param $params
	 * @return array
	 */
	public static function updateSubmissionHook($params, $L)
	{
		$file_fields = $params["file_fields"];

		if (empty($file_fields)) {
			return array(true, "");
		}

		$form_id = $params["form_id"];
		$submission_id = $params["submission_id"];
		$module_field_type_id = FieldTypes::getFieldTypeIdByIdentifier("file");

		$num_uploaded_files = 0;
		$all_successful = true;
		$file_size_errors = array();
		$file_extension_errors = array();
		$file_rename_errors = array();

		foreach ($file_fields as $file_field_info) {
			$field_type_id = $file_field_info["field_info"]["field_type_id"];
			$field_name = $file_field_info["field_info"]["field_name"];

			if ($field_type_id != $module_field_type_id) {
				continue;
			}

			// nothing was included in this field, just ignore it - TODO
			if (empty($_FILES[$field_name]["name"])) {
				continue;
			}

			$num_uploaded_files += count($uploaded_files);

			if (!$success) {
				$all_successful = false;
			}
			if (!empty($errors["file_size_errors"])) {
				$file_size_errors = array_merge($file_size_errors, $errors["file_size_errors"]);
			}
			if (!empty($errors["file_extension_errors"])) {
				$file_extension_errors = array_merge($file_extension_errors, $errors["file_extension_errors"]);
			}
			if (!empty($errors["file_rename_errors"])) {
				$file_rename_errors = array_merge($file_rename_errors, $errors["file_rename_errors"]);
			}
		}

		//print_r($file_size_errors);

		$lines = array();
		if (!empty($file_size_errors) || !empty($file_extension_errors) || !empty($file_rename_errors)) {

			if ($num_uploaded_files > 0) {
				$lines[] = $L["notify_submission_updated_file_problems"];
			}

			if (count($file_size_errors) == 1) {
				$lines[] = General::evalSmartyString($L["notify_file_too_large"], array(
					"filename" => $file_size_errors[0]["filename"],
					"file_size" => $file_size_errors[0]["actual_size"],
					"max_file_size" => $file_size_errors[0]["max_file_size"]
				));
			} else if (count($file_size_errors) > 1) {
				$lines[] = General::evalSmartyString($L["notify_files_too_large"], array(
					"field_title" => $file_field_info["field_info_info"]["field_title"],
					"file_size" => $file_upload_max_size,
					"file_list" => implode("</b>, <b>", $file_size_errors)
				));
			}
		}

		echo $all_successful;


		// $LANG["notify_unsupported_file_extension"]
		// $L["notify_file_too_large"] = "The {filename} file is too large. The file was {\$FILESIZE}KB, but the maximum permitted file upload size is {\$MAXFILESIZE}KB.";
		// $error = General::evalSmartyString($LANG["notify_file_too_large"], $placeholders);

		return array(
			"success" => $all_successful,
			"message" => implode("<br />", $lines)
		);

//		if (!empty($problem_files)) {
//			$message = $L["notify_submission_updated_file_problems"] . "<br /><br />";
//			foreach ($problem_files as $problem) {
//				$message .= "&bull; <b>{$problem[0]}</b>: $problem[1]<br />\n";
//			}
//
//			$return_info = array(
//				"success" => false,
//				"message" => $message
//			);
//		}
//
//		return $return_info;
	}


	/**
	 * Uploads a file for a particular form submission field.
	 *
	 * @param integer $form_id the unique form ID
	 * @param integer $submission_id a unique submission ID
	 * @param array $file_field_info
	 * @return array returns array with indexes:<br/>
	 *               [0]: true/false (success / failure)<br/>
	 *               [1]: message string
	 */
	public static function uploadSubmissionFile($form_id, $submission_id, $file_field_info, $L)
	{
		$db = Core::$db;
		$LANG = Core::$L;

		// get the column name and upload folder for this field
		$col_name = $file_field_info["field_info"]["col_name"];

		// if the column name wasn't found, the $field_id passed in was invalid. Somethin' aint right...
		if (empty($col_name)) {
			return array(false, $LANG["notify_submission_no_field_id"]);
		}

		$is_multiple_files = $file_field_info["settings"]["multiple_files"];
		$field_name = $file_field_info["field_info"]["field_name"];
		$file_upload_max_size = $file_field_info["settings"]["max_file_size"];
		$file_upload_dir = $file_field_info["settings"]["folder_path"];
		$permitted_file_types = $file_field_info["settings"]["permitted_file_types"];

		// check upload folder is valid and writable
		if (!is_dir($file_upload_dir) || !is_writable($file_upload_dir)) {
			return array(false, $LANG["notify_invalid_field_upload_folder"]);
		}

		$fileinfo = self::extractSingleFieldFileUploadData($is_multiple_files, $field_name, $_FILES);

		$final_file_upload_info = array();
		$file_size_errors = array();
		$file_extension_errors = array();

		foreach ($fileinfo as $row) {

			// check the file isn't too large
			if ($row["filesize"] > $file_upload_max_size) {
				$file_size_errors[] = array(
					"filename" => $row["original_filename"],
					"actual_size" => round($row["filesize"], 1),
					"max_file_size" => $file_upload_max_size
				);
				continue;
			}

			// check file extension is valid. Note: this is rather basic - it just tests for the file extension string,
			// not the actual file type based on its header info [this is done because I want to allow users to permit
			// uploading of any file types, and I can't know about all header types]
			$is_valid_extension = true;
			if (!empty($permitted_file_types)) {
				$is_valid_extension = false;
				$raw_extensions = explode(",", $permitted_file_types);

				foreach ($raw_extensions as $ext) {
					$clean_extension = str_replace(".", "", trim($ext)); // remove whitespace and periods
					if (preg_match("/$clean_extension$/i", $row["filename"])) {
						$is_valid_extension = true;
					}
				}
			}

			if (!$is_valid_extension) {
				$file_extension_errors[] = $row["original_filename"];
				continue;
			}

			$final_file_upload_info[] = array(
				"tmp_filename" => $row["tmp_filename"],
				"original_filename" => $row["original_filename"],
				"unique_filename" => Files::getUniqueFilename($file_upload_dir, $row["filename"])
			);
		}

		// find out if there was already a file/files uploaded in this field. For single file upload fields, uploading
		// a new file removes the old one. For files that allow multiple uploads, uploading a new file just appends it
		$submission_info = Submissions::getSubmissionInfo($form_id, $submission_id);
		$old_filename = (!empty($submission_info[$col_name])) ? $submission_info[$col_name] : "";

		if ($is_multiple_files === "no") {
			self::removeOldSubmissionFieldFiles($old_filename, $file_upload_dir);
		}

		$successfully_uploaded_files = array();
		$upload_file_errors = array();
		foreach ($final_file_upload_info as $row) {
			if (@rename($row["tmp_filename"], "$file_upload_dir/{$row["unique_filename"]}")) {
				@chmod("$file_upload_dir/{$row["unique_filename"]}", 0777);
				$successfully_uploaded_files[] = $row["unique_filename"];
			} else {
				$upload_file_errors[] = $row["original_filename"];
			}
		}

		// update the database record with whatever's been uploaded
		$success = false;
		$file_list = $successfully_uploaded_files;
		if (!empty($successfully_uploaded_files)) {
			$success = true;
			if ($is_multiple_files) {
				$existing_files = empty($old_filename) ? array() : explode(":", $old_filename);
				$new_files = $successfully_uploaded_files;
				$file_list = array_merge($existing_files, $new_files);
				$file_list_str = implode(":", $file_list);
			} else {
				$file_list_str = implode(":", $file_list);
			}

			$db->query("
				UPDATE {PREFIX}form_{$form_id}
				SET    $col_name = :file_names
				WHERE  submission_id = :submission_id
			");
			$db->bindAll(array(
				"file_names" => $file_list_str,
				"submission_id" => $submission_id
			));
			$db->execute();
		}

		return array($success, $file_list, array(
			"file_size_errors" => $file_size_errors,
			"file_extension_errors" => $file_extension_errors,
			"file_rename_errors" => $upload_file_errors
		));
	}


	/**
	 * Deletes one or more file that has been uploaded through a single form submission file field. This is only ever
	 * called via an ajax request.
	 *
	 * @param integer $form_id the unique form ID
	 * @param integer $submission_id a unique submission ID
	 * @param integer $field_id a unique form field ID
	 * @param array $files a list of filenames
	 * @param boolean $force_delete this forces the file to be deleted from the database, even if the
	 *                file itself doesn't exist or doesn't have the right permissions.
	 * @param array
	 * @return array Returns array with indexes:<br/>
	 *               [0]: true/false (success / failure)<br/>
	 *               [1]: message string<br/>
	 */
	public static function deleteFilesFromField($form_id, $submission_id, $field_id, $files, $force_delete, $L)
	{
		$db = Core::$db;

		// get the column name and upload folder for this field
		$field_info = Fields::getFormField($field_id);
		$col_name = $field_info["col_name"];

		// if the column name wasn't found, the $field_id passed in was invalid. Return false.
		if (empty($col_name)) {
			return array(false, $L["notify_submission_no_field_id"]);
		}

		// confirm all files passed are actually listed as part of the field. Just ignore any that aren't.
		$submission_info = Submissions::getSubmissionInfo($form_id, $submission_id);
		$existing_files = explode(":", $submission_info[$col_name]);
		$files_to_delete = array();
		foreach ($files as $filename) {
			if (in_array($filename, $existing_files)) {
				$files_to_delete[] = $filename;
			}
		}

		if (empty($files_to_delete)) {
			return array(false, $L["phrase_no_files_to_delete"]);
		}

		$field_settings = Fields::getFieldSettings($field_id);
		$file_folder = $field_settings["folder_path"];

		$update_database_record = false;
		$updated_field_value = '';
		$success = true;
		$undeleted_files = array();

		if ($force_delete) {
			self::deleteFiles($file_folder, $files_to_delete);
			$message = $L["notify_file_deleted"];
			$update_database_record = true;
		} else {

			list($all_deleted_successfully, $undeleted_files) = self::deleteFiles($file_folder, $files_to_delete);

			$remaining_files = array();
			if ($all_deleted_successfully) {
				$success = true;
				$update_database_record = true;
				$message = (count($files_to_delete) > 1) ? $L["notify_files_deleted"] : $L["notify_file_deleted"];

				foreach ($existing_files as $file) {
					if (!in_array($file, $files_to_delete)) {
						$remaining_files[] = $file;
					}
				}
			} else {
				// here there was a problem deleting one of the actual files. Cater the display message to say precisely
				// what went wrong, but update the database to remove any files that were successfully uploaded
				$num_deleted = count($files_to_delete) - count($undeleted_files);
				$success = false;
				$message = self::getDeleteFileErrorMessage($file_folder, $num_deleted, $undeleted_files, $L);

				foreach ($existing_files as $file) {
					if (!in_array($file, $files_to_delete) || in_array($file, $undeleted_files)) {
						$remaining_files[] = $file;
					}
				}
			}
			$updated_field_value = implode(":", $remaining_files);
		}

		// if need be, update the database record to remove the reference to the file in the database. Generally this
		// should always work, but in case something funky happened, like the permissions on the file were changed to
		// forbid deleting, I think it's best if the record doesn't get deleted to remind the admin/client it's still
		// there.
		if ($update_database_record) {
			$db->query("
                UPDATE {PREFIX}form_{$form_id}
                SET    $col_name = :updated_field_value
                WHERE  submission_id = :submission_id
            ");
			$db->bindAll(array(
				"updated_field_value" => $updated_field_value,
				"submission_id" => $submission_id
			));
			$db->execute();
		}

		// TODO look this over. Maybe just move back to Module would make things easiest...
		extract(CoreHooks::processHookCalls("end", compact("form_id", "submission_id", "field_id", "force_delete"), array("success", "message")), EXTR_OVERWRITE);

		$deleted_files = array();
		foreach ($existing_files as $file) {
			if (in_array($file, $files_to_delete) && !in_array($file, $undeleted_files)) {
				$deleted_files[] = $file;
			}
		}

		return array($success, $message, $deleted_files);
	}


	// -----------------------------------------------------------------------------------------------------------------
	// helpers


	/**
	 * Returns an array of hashes. Each hash contains details about the file being uploaded; if there's a single file,
	 * the top level array contains a single hash.
	 * @param $is_multiple_files
	 * @param $field_name
	 * @param $files
	 * @return array
	 */
	private static function extractSingleFieldFileUploadData($is_multiple_files, $field_name, $files)
	{
		$file_info = $files[$field_name];

		// clean up the filename according to the whitelist chars
		$file_data = array();
		if ($is_multiple_files == "no") {
			$file_data[] = self::getSingleUploadedFileData($file_info["name"], $file_info["size"], $file_info["tmp_name"]);
		} else {
			$num_files = count($files[$field_name]["name"]);
			for ($i = 0; $i < $num_files; $i++) {
				$file_data[] = self::getSingleUploadedFileData($file_info["name"][$i], $file_info["size"][$i], $file_info["tmp_name"][$i]);
			}
		}

		return $file_data;
	}


	private static function getSingleUploadedFileData($filename, $file_size, $tmp_name)
	{
		$char_whitelist = Core::getFilenameCharWhitelist();
		$valid_chars = preg_quote($char_whitelist);

		$filename_parts = explode(".", $filename);
		$extension = $filename_parts[count($filename_parts) - 1];
		array_pop($filename_parts);
		$filename_without_extension = implode(".", $filename_parts);

		$filename_without_ext_clean = preg_replace("/[^$valid_chars]/", "", $filename_without_extension);
		if (empty($filename_without_ext_clean)) {
			$filename_without_ext_clean = "file";
		}

		return array(
			"original_filename" => $filename,
			"filename" => $filename_without_ext_clean . "." . $extension,
			"filesize" => $file_size / 1000,
			"tmp_filename" => $tmp_name
		);
	}


	/**
	 * Removes any file(s) for a single form field.
	 *
	 * Called when uploading a new file into a field flagged with "is_multiple" = no. Generally a field with
	 * that configuration will only ever contain a single file, but the user may have just switched the field from
	 * multiple to single, so the field actually contains MULTIPLE filenames.
	 *
	 * @param $submission_field_value
	 * @param $file_upload_dir
	 */
	private static function removeOldSubmissionFieldFiles($submission_field_value, $file_upload_dir)
	{
		if (!empty($submission_field_value)) {
			return;
		}

		$files = explode(":", $submission_field_value);

		foreach ($files as $file) {
			if (file_exists("$file_upload_dir/$file")) {
				@unlink("$file_upload_dir/$file");
			}
		}
	}


	private static function deleteFiles($folder, $files)
	{
		$success = true;
		$undeleted_files = array();
		foreach ($files as $file) {
			if (!@unlink("$folder/$file")) {
				$success = false;
				$undeleted_files[] = $file;
			}
		}
		return array($success, $undeleted_files);
	}



	/**
	 * Constructs a human-friendly message after one or more files weren't deleted for a particular form field.
	 * @param $folder
	 * @param $num_deleted
	 * @param $undeleted_files
	 * @param $L
	 * @return string
	 */
	private static function getDeleteFileErrorMessage($folder, $num_deleted, $undeleted_files, $L)
	{
		$lines = array();
		$indent = "";
		if ($num_deleted > 0) {
			$lines[] = "<b>{$num_deleted}</b> files were successfully deleted, but the following errors occurred:";
			$indent = "&bull; ";
		}

		$missing_files = array();
		$invalid_permissions = array();
		$unknown_errors = array();

		foreach ($undeleted_files as $file) {
			$full_path = "$folder/$file";
			if (!is_file($full_path)) {
				$missing_files[] = $file;
			} else if (!is_readable($full_path) || !is_writable($full_path)) {
				$invalid_permissions[] = $file;
			} else {
				$unknown_errors[] = $file;
			}
		}

		$show_clear_error_line = true;
		if (!empty($missing_files)) {
			if (count($missing_files) === 1) {
				$lines[] = "{$indent}The <b>{$missing_files[0]}</b> file hasn't been deleted because it doesn't exist in the expected folder (<b>$folder</b>).";
			} else {
				$lines[] = "{$indent}The following files haven't been deleted because they doesn't exist in the expected folder (<b>$folder</b>): <b>"
					. implode("</b>, <b>", $missing_files) . "</b>.";
			}

//			$replacements = array("js_link" => "return files_ns.delete_submission_file($field_id, true)");
//			$message = General::evalSmartyString($L["notify_file_not_deleted_no_exist"] . "($folder/$file)", $replacements);
			if (empty($invalid_permissions) && empty($unknown_errors)) {
				$lines[] .= "<a href=\"#\" onclick=\"{\$js_link}\">Click here</a> to ignore this error message and just remove the reference from the database.";
				$show_clear_error_line = false;
			}
		}

		if (!empty($invalid_permissions)) {

		}

		if ($show_clear_error_line) {
			$lines[] = "<a href=\"#\">Click here</a> to ignore these errors and remove the references from the submission data.";
		}


//		} else {
//			if (is_file("$file_folder/$file") && (!is_readable("$file_folder/$file") || !is_writable("$file_folder/$file"))) {
//				$success = false;
//				$update_database_record = false;
//				$replacements = array("js_link" => "return files_ns.delete_submission_file($field_id, true)");
//				$message = General::evalSmartyString($LANG["notify_file_not_deleted_permissions"], $replacements);
//			} else {
//				$success = false;
//				$update_database_record = false;
//				$replacements = array("js_link" => "return files_ns.delete_submission_file($field_id, true)");
//				$message = General::evalSmartyString($LANG["notify_file_not_deleted_unknown_error"], $replacements);
//			}
//		}

		return implode("<br />", $lines);
	}
}