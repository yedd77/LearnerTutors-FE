<?php

// Course Learning Assistant landing page
$string['pluginname'] = 'Course Learning Assistant'; # Plugin name
$string['dashboardheading'] = 'Course Learning Assistant'; # Plugin Heading
$string['uploadCardTitle'] = 'Manage Course Materials'; # Card title
$string['uploadCardDesc'] = 'Upload and manage learning materials available to the assistant for this course.'; # Card description
$string['uploadButton'] = 'Manage documents'; # Card button
$string['askassistant'] = 'Ask the Course Learning Assistant'; # Card title
$string['chatintro'] = 'Ask questions about permitted course materials and review cited sources.'; # Card description
$string['openchat'] = 'Open chat'; # Button
$string['settingCardTitle'] = 'Permission Settings'; # Card title
$string['settingCardDesc'] = 'Manage permission settings for Couse Learning Assistant.'; # Card description
$string['coursepolicytitle'] = 'Manage settings'; // Card Button

// Upload document form components
$string['submitdocument'] = 'Upload course material document';
$string['submitdocumentdesc'] = 'Add the learning materials to become context for the assistant';
$string['documentfile'] = 'Document file(s)';
$string['documentfile_help'] = 'Select PDF, DOCX, PPTX, TXT, or Markdown file(s), up to 20 MB. Maximum 20 files per upload.';
$string['documentreviewtitle'] = 'Review files';
$string['documentreviewdesc'] = 'Review and add additional notes for each files';
$string['uploadfilesbutton'] = 'Upload all files';
$string['lecturernote'] = 'Lecturer note';
$string['retrieval'] = 'Retrieval';
$string['uploadedDocuments'] = 'Manage uploaded documents';
$string['manageUploaded'] = 'Disable or delete uploaded documents. Disabling a document prevents it from being used by the assistant, but does not delete it. Deleting a document removes it permanently.';

// Chatbot page components
$string['chatwelcome'] = 'Ask questions about your course materials. Answers will eventually include references to source documents.';
$string['chatprivacyinfo'] = 'Your question and the text retrieved from documents available to you in this course are sent to our configured AI model. Do not submit passwords or other sensitive personal information.';
$string['chatinputhint'] = 'AI can make mistake. Do verify important information against the cited source.';
$string['sendquestion'] = 'Send';
$string['questionplaceholder'] = 'For example: Summarise the main concepts in Week 3 notes.';
$string['yourquestion'] = 'Your question';
$string['sourcepage'] = 'Page / slide';
$string['sourcesheading'] = 'Sources used';
$string['unknown'] = 'Unknown';
$string['questionrequired'] = 'Enter a question with at least two characters.';
$string['questiontoolong'] = 'Keep your question under 4,000 characters.';


// Settings page components
$string['allowstudentchat'] = 'Allow students to use the AI chat';
$string['allowstudentchat_desc'] = 'When disabled, students cannot open or submit questions to the assistant in this course. Educators with document-management permission remain able to use it.';
$string['allowstudentupload'] = 'Allow students to upload personal documents';
$string['allowstudentupload_desc'] = 'When disabled, only educators with document-management permission can upload documents. Student uploads, when allowed, remain private to the uploader.';
$string['coursepolicyhelp'] = 'Choose whether enrolled students may use the chat or upload their own documents. Educators with document-management permission can always use chat and manage course documents. Changes apply to this course only.';
$string['policysaved'] = 'Course assistant permissions saved.';

// Utility strings
$string['backtodashboard'] = 'Back to Course Learning Asistant dashboard';


$string['uploadfailed'] = 'The file was not stored. Please try again.';
$string['uploadnotpermitted'] = 'You do not have permission to upload documents in this course.';
$string['documentname'] = 'Document';
$string['visibility'] = 'Visibility';
$string['processingstatus'] = 'Processing status';
$string['retrieval'] = 'Retrieval';
$string['dateuploaded'] = 'Date uploaded';
$string['personal'] = 'Uploader only';
$string['course'] = 'Course participants';
$string['pendingfilename'] = 'Uploaded file';
$string['status_pending'] = 'Pending indexing';
$string['status_processing'] = 'Processing';
$string['status_ready'] = 'Ready';
$string['status_failed'] = 'Failed';
$string['nodocuments'] = 'No documents have been uploaded for this course yet.';



$string['chatdisabledreason'] = 'Chat is disabled in site settings. Configure and test FastAPI and the selected AI provider before enabling it.';


$string['apikeymissing'] = 'The RAG API key is not configured. Ask a site administrator to configure it.';
$string['backendconnectionfailed'] = 'Could not connect to the RAG backend. Check that FastAPI is running and the base URL is correct.';
$string['backendrequestfailed'] = 'The RAG backend rejected the request: {$a}';
$string['backendinvalidresponse'] = 'The RAG backend returned an invalid response.';
$string['filetempfailed'] = 'Moodle could not prepare the stored document for indexing.';
$string['storedfilemissing'] = 'The stored document file could not be found.';
$string['indexsuccess'] = 'Document indexed successfully ({$a} text chunks).';
$string['indexdocument'] = 'Index';
$string['actions'] = 'Actions';

$string['answerfailed'] = 'The assistant could not answer right now. Check that the backend and configured AI provider are available, then try again.';

$string['notavailable'] = 'Not available';



$string['studentchatdisabled'] = 'The lecturer has disabled student access to the AI chat for this course.';

// Plugin Settings compoments
$string['api_base_url'] = 'FastAPI base URL';
$string['api_base_url_desc'] = 'Base URL of your RAG backend, without an API path.';
$string['api_key'] = 'FastAPI API key';
$string['api_key_desc'] = 'API key used to authenticate requests to the RAG backend.';
$string['reindexpending'] = 'The embedding configuration has changed. Course documents are being reindexed so their vectors remain compatible. Chat-generation model changes do not require reindexing. Please try again after Moodle scheduled tasks have completed.';



// Settings page components
// Plugin Settings compoments
$string['settingsheading'] = 'Plugin settings';
$string['settingsintro'] = 'Configure the local FastAPI service. Do not expose this service publicly without authentication and network restrictions.';
$string['enablechat'] = 'Enable AI chat';
$string['enablechat_desc'] = 'Global setting to enable AI chat feature.';
$string['apibaseurl'] = 'Server API endpoint';
$string['apibaseurl_desc'] = 'Endpoint used to invoke the API call.';
// LLM config settings 
$string['llm_heading'] = 'LLM configuration';
$string['llm_heading_desc'] = 'Configure the LLM settings for the RAG system.';
$string['llmprovider'] = 'Chat provider';
$string['llmprovider_desc'] = 'Select the AI provider used for chat generation. Enter its matching credential in the single Provider API key field below.';
$string['llm_model'] = 'Chat model';
$string['llm_model_desc'] = 'Required chat model identifier supplied to the backend. Changing the generation model does not require document reindexing.';
$string['image_heading'] = 'Image generation configuration';
$string['image_heading_desc'] = 'Choose the provider and model used for /explain illustrations. Image generation is configured independently from chat generation.';
$string['image_provider'] = 'Image provider';
$string['image_provider_desc'] = 'Select OpenAI or Google Gemini for illustration generation. Ollama is not available for image generation.';
$string['image_model'] = 'Image generation model';
$string['image_model_desc'] = 'Model identifier for the selected image provider. Examples: gpt-image-1 for OpenAI or gemini-3.1-flash-image for Gemini.';
$string['reuse_chat_api_key'] = 'Reuse chat provider API key';
$string['reuse_chat_api_key_desc'] = 'Use the chat provider credential for image generation when both provider selections are the same. If the providers differ, leave this unchecked and enter a separate image provider API key below. The backend rejects reuse when the providers do not match.';
$string['image_provider_api_key'] = 'Image provider API key';
$string['image_provider_api_key_desc'] = 'Enter the API key for the selected image provider. This is stored separately from the chat key, encrypted in Moodle, and sent only to FastAPI. Leave blank to keep the existing image key. Not required when reuse is enabled and the chat and image providers match.';
$string['apikey'] = 'Application API key';
$string['apikey_desc'] = 'Enter the shared key matching RAG_API_KEY on FastAPI. The field is blank to avoid sending the saved key to your browser. Leave blank to keep the current key. Moodle encrypts the value at rest using an automatically generated key stored in the protected moodledata directory. Advanced deployments may provide LOCAL_RAGASSISTANT_ENCRYPTION_KEY or $CFG->local_ragassistant_encryption_key instead.';
$string['encryptionkeymissing'] = 'Moodle could not access its protected data directory to initialize secure credential storage. Check the dataroot path and PHP write permissions.';
$string['encryptionkeyinvalid'] = 'The configured Moodle credential encryption key is invalid, or the saved key file is damaged. Restore the original key from backup; do not generate a replacement while encrypted credentials exist.';
$string['secretencryptionfailed'] = 'Moodle could not encrypt the application API key.';
$string['secretdecryptionfailed'] = 'Moodle could not decrypt the stored application API key. Verify that the original Moodle credential encryption key or moodledata key file has been restored.';
$string['apikeytoolong'] = 'Use an application API key with at least 32 characters.';
$string['secretstoragefailed'] = 'The application API key could not be stored securely.';
$string['apikey_desc_secure'] = 'Enter the shared key matching RAG_API_KEY on FastAPI. The field is blank to avoid sending the saved key to your browser. Leave blank to keep the current key. Moodle encrypts the value at rest using an automatically generated key stored in the protected moodledata directory. Advanced deployments may provide LOCAL_RAGASSISTANT_ENCRYPTION_KEY or $CFG->local_ragassistant_encryption_key.';
$string['testconnection'] = 'Test FastAPI connection';
$string['testconnectionheading'] = 'Test FastAPI authentication';
$string['testconnectionintro'] = 'Send a server-to-server authentication test using the configured URL and shared key. The key is never displayed.';
$string['testconnectionsuccess'] = 'FastAPI connection and API-key authentication succeeded.';
$string['testconnectionfailed'] = 'The connection test failed: {$a}';
$string['backendhttpsrequired'] = 'The FastAPI URL must use HTTPS. HTTP is allowed only for localhost or loopback development addresses.';

// AI provider credential settings


// Combined provider credential setting
$string['provider_api_key'] = 'Provider API key';
$string['provider_api_key_desc'] = 'Enter the API key for the provider selected above (OpenAI or Google Gemini). The key is encrypted in Moodle and synchronized securely to FastAPI. The field stays blank after saving; leave it blank to keep the selected provider’s existing key. Switching providers does not delete the other provider’s saved key. Ollama does not require an API key.';
$string['providerkeynotrequired'] = 'Ollama does not require an API key. Select OpenAI or Google Gemini to save a provider credential.';
$string['providerkeytoolong'] = 'Use a provider API key with at least 20 characters.';


// Encrypted credential status labels (never display credential values).
$string['credentialconfigured'] = 'Configured — saved credential is available';
$string['credentialnotconfigured'] = 'Not configured — enter an API key';
$string['credentialnotrequired'] = 'Not required for the selected provider';
$string['credentialverificationfailed'] = 'A saved credential exists but could not be verified. Check the encryption key and restore it from backup if necessary.';
$string['credentialinputplaceholder'] = '*******************************';
