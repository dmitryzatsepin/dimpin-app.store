import { useEffect, useState } from 'react';
import {
    Select, Loader, Alert, Stack, Title, Text, Table, Paper, ScrollArea, Button, Modal, Progress,
    Accordion, List, Group, TextInput
} from '@mantine/core';
import { IconAlertCircle } from '@tabler/icons-react';
import axios from 'axios';

import { useSmartProcesses } from '../hooks/useSmartProcesses';
import { useProcessFields } from '../hooks/useProcessFields';
import { useFileHandler, type FileRow, type FileWithPath } from '../hooks/useFileHandler';
import { useFieldMappings } from '../hooks/useFieldMappings';
import { FileUploadSection } from './FileUploadSection';
import { type ImportAttemptResponse, type ImportApiResponseErrorItem } from '../types';

const SmartProcessSelector = () => {
    const { smartProcesses, loadingProcesses, errorProcesses } = useSmartProcesses();
    const [selectedProcessId, setSelectedProcessId] = useState<string | null>(null);
    const { fields, loadingFields, fieldsError, setFields: setProcessFieldsHook } = useProcessFields(selectedProcessId);
    const {
        uploadedFile, fileHeaders, parsingFile, fileError,
        handleFileDrop, readFileData, clearFile: clearUploadedFile, setFileError
    } = useFileHandler();
    const { fieldMappings, handleMappingChange, clearMappings: clearFieldMappings } = useFieldMappings(fields, fileHeaders);

    const [globalMultipleDelimiter, setGlobalMultipleDelimiter] = useState<string>(',');
    const [customDateFormat, setCustomDateFormat] = useState<string>('');
    const [isImporting, setIsImporting] = useState<boolean>(false);
    const [importProgress, setImportProgress] = useState<number>(0);
    const [importStatusMessage, setImportStatusMessage] = useState<string | null>(null);
    const [isStatusModalOpen, setIsStatusModalOpen] = useState<boolean>(false);
    const [detailedErrors, setDetailedErrors] = useState<ImportApiResponseErrorItem[]>([]);

    const handleStartNewImport = () => {
        setIsStatusModalOpen(false);
        clearUploadedFile();
        clearFieldMappings();
        const currentId = selectedProcessId;
        setSelectedProcessId(null);
        setTimeout(() => setSelectedProcessId(currentId), 10);
    };

    useEffect(() => {
        if (errorProcesses) {
            setSelectedProcessId(null);
            setProcessFieldsHook([]);
        }
        clearUploadedFile();
        clearFieldMappings();
        setDetailedErrors([]);
        setImportStatusMessage(null);
        setIsStatusModalOpen(false);
    }, [selectedProcessId, fieldsError, errorProcesses, clearUploadedFile, clearFieldMappings, setProcessFieldsHook]);

    const handleProceedToImport = async () => {
        if (!uploadedFile || !selectedProcessId || !fields) {
            setImportStatusMessage("Please select a smart process, upload a file, and map fields.");
            setDetailedErrors([]);
            setIsStatusModalOpen(true);
            return;
        }

        setIsImporting(true);
        setDetailedErrors([]);
        setIsStatusModalOpen(true);
        setImportProgress(0);

        const wait = (ms: number) => new Promise(resolve => setTimeout(resolve, ms));

        try {
            setImportProgress(10);
            setImportStatusMessage("Step 1/4: Reading file data...");
            const rows: FileRow[] = await readFileData();
            if (rows.length === 0) throw new Error("No data rows found in the file.");

            await wait(300);

            setImportProgress(25);
            setImportStatusMessage(`Step 2/4: Preparing ${rows.length} items...`);
            const mappableFieldsForImport = fields.filter(field => !field.isReadOnly);
            const dataToImport = rows.map(row => {
                const itemFields: Record<string, any> = {};
                mappableFieldsForImport.forEach(processField => {
                    const fileColumnHeader = fieldMappings[processField.id];
                    if (fileColumnHeader && Object.prototype.hasOwnProperty.call(row, fileColumnHeader)) {
                        let cellValue = row[fileColumnHeader] !== null && row[fileColumnHeader] !== undefined ? String(row[fileColumnHeader]) : null;
                        if (cellValue !== null && cellValue.trim() !== "") {
                            if (processField.isMultiple && globalMultipleDelimiter) {
                                itemFields[processField.id] = cellValue.split(globalMultipleDelimiter).map(s => s.trim()).filter(s => s !== "");
                            } else {
                                itemFields[processField.id] = cellValue.trim();
                            }
                        }
                    }
                });
                return itemFields;
            }).filter(item => Object.keys(item).length > 0);
            if (dataToImport.length === 0) throw new Error("No data to import after mapping.");

            await wait(300);

            setImportProgress(50);
            setImportStatusMessage(`Step 3/4: Sending data to server...`);
            const initialData = window.bx24InitialData;
            const config = window.appConfig;
            if (!initialData?.member_id || !initialData?.domain || !config?.apiBaseUrl) {
                throw new Error("Application configuration error.");
            }
            const payload = {
                entityTypeId: selectedProcessId,
                items: dataToImport,
                member_id: initialData.member_id,
                DOMAIN: initialData.domain,
                customDateFormat: customDateFormat.trim()
            };

            await wait(300);

            setImportProgress(75);
            setImportStatusMessage(`Step 4/4: Waiting for Bitrix24 to process... (this may take a while)`);
            const response = await axios.post<ImportAttemptResponse>(`${config.apiBaseUrl}?action=import_data`, payload, { timeout: 180000 });
            setImportProgress(100);

            if (response.data) {
                const selectedProcess = smartProcesses.find(p => p.id === selectedProcessId);
                const processTitle = selectedProcess ? `'${selectedProcess.title}'` : 'the smart process';
                const messageParts: string[] = [];
                if (response.data.importedCount && response.data.importedCount > 0) {
                    messageParts.push(`Created ${response.data.importedCount} new item(s)`);
                }
                if (response.data.updatedCount && response.data.updatedCount > 0) {
                    messageParts.push(`Updated ${response.data.updatedCount} existing item(s)`);
                }
                let finalMessage = "Import process finished.";
                if (messageParts.length > 0) {
                    finalMessage = `${messageParts.join(' and ')} in ${processTitle}.`;
                }
                if (response.data.failedCount && response.data.failedCount > 0) {
                    finalMessage += ` Failed to process ${response.data.failedCount} item(s).`;
                }
                setImportStatusMessage(finalMessage);
                if (response.data.errors && response.data.errors.length > 0) {
                    setDetailedErrors(response.data.errors);
                }
            } else {
                throw new Error('Received an empty or invalid response from the server.');
            }
        } catch (err: any) {
            console.error("Error during import process:", err);
            setImportProgress(100);
            let errorMessage = 'An unknown error occurred during import.';
            if (axios.isAxiosError(err) && err.response && err.response.data) {
                const resData = err.response.data as ImportAttemptResponse;
                errorMessage = `Server error: ${resData.message || resData.error || 'No specific error message.'}`;
                if (resData.errors && resData.errors.length > 0) {
                    setDetailedErrors(resData.errors);
                } else {
                    setDetailedErrors([{ itemIndexOriginal: 'General', errorDetails: errorMessage }]);
                }
            } else if (err instanceof Error) {
                errorMessage = err.message;
                setDetailedErrors([{ itemIndexOriginal: 'Client-Side', errorDetails: err.message }]);
            }
            setImportStatusMessage(`Error: ${errorMessage}`);
        } finally {
            setIsImporting(false);
        }
    };

    if (loadingProcesses) return (<Stack align="center" mt="xl"><Loader /><Text>Loading smart process list...</Text></Stack>);
    if (errorProcesses) return <Alert icon={<IconAlertCircle size="1rem" />} title="Error Loading Processes!" color="red" mt="xl">{errorProcesses}</Alert>;

    const selectData = smartProcesses.map(sp => ({ value: String(sp.id), label: sp.title }));
    const mappableFields = fields ? fields.filter(field => !field.isReadOnly) : [];

    const fieldRows = mappableFields.map((field) => (
        <Table.Tr key={field.id}>
            <Table.Td>{field.title}</Table.Td>
            <Table.Td>{field.type}</Table.Td>
            <Table.Td>{field.isMultiple ? 'Yes' : 'No'}</Table.Td>
            <Table.Td>
                <Select
                    placeholder="Map to column..."
                    data={fileHeaders.map(header => ({ value: header, label: header }))}
                    value={fieldMappings[field.id] || null}
                    onChange={(value) => handleMappingChange(field.id, value)}
                    size="xs" clearable searchable
                    nothingFoundMessage="No matching header"
                    disabled={fileHeaders.length === 0 || parsingFile}
                />
            </Table.Td>
        </Table.Tr>
    ));

    return (
        <Stack gap="xl" p="md">
            <Modal
                opened={isStatusModalOpen}
                onClose={() => setIsStatusModalOpen(false)}
                title={isImporting ? "Import in Progress" : "Import Status"}
                size={detailedErrors.length > 0 ? "xl" : "lg"}
                centered
                scrollAreaComponent={ScrollArea.Autosize}
            >
                {isImporting && <Progress value={importProgress} striped animated mb="md" />}
                <Text style={{ whiteSpace: 'pre-wrap' }} mb="md">{importStatusMessage}</Text>
                {detailedErrors.length > 0 && !isImporting && (
                    <Paper withBorder p="md" mt="md">
                        <Accordion defaultValue="errors-panel" chevronPosition="left">
                            <Accordion.Item value="errors-panel">
                                <Accordion.Control>
                                    <Text color="red" fw={700}>{detailedErrors.length} Warning(s)/Error(s) Occurred (click to expand)</Text>
                                </Accordion.Control>
                                <Accordion.Panel>
                                    <ScrollArea.Autosize mah={300} type="auto">
                                        <List spacing="xs" size="sm" withPadding>
                                            {detailedErrors.map((err, index) => (
                                                <List.Item key={index} icon={err.type?.includes('warning') || err.type?.includes('fallback') ? <IconAlertCircle size={16} color="orange" /> : <IconAlertCircle size={16} color="red" />}>
                                                    <Text fz="sm">
                                                        <Text fw={700} component="span">
                                                            {`Row ${err.itemIndexOriginal}`}:
                                                        </Text>{' '}
                                                        {err.errorDetails}
                                                    </Text>
                                                </List.Item>
                                            ))}
                                        </List>
                                    </ScrollArea.Autosize>
                                </Accordion.Panel>
                            </Accordion.Item>
                        </Accordion>
                    </Paper>
                )}
                {!isImporting && (
                    <Group mt="lg" grow>
                        <Button onClick={() => setIsStatusModalOpen(false)}>Close</Button>
                        <Button variant="outline" onClick={handleStartNewImport}>Start New Import</Button>
                    </Group>
                )}
            </Modal>
            <Stack gap="xs">
                <Title order={3}>1. Select Smart Process</Title>
                <Select
                    label="Smart Process for Import"
                    placeholder="Select from list"
                    data={selectData}
                    value={selectedProcessId}
                    onChange={(value) => {
                        setSelectedProcessId(value);
                        if (!value) { setProcessFieldsHook([]); }
                    }}
                    searchable nothingFoundMessage="No smart processes found"
                    disabled={smartProcesses.length === 0 || loadingProcesses}
                    clearable
                />
            </Stack>
            {fieldsError && (<Alert icon={<IconAlertCircle size="1rem" />} title="Error Loading Fields!" color="red" mt="md">{fieldsError}</Alert>)}
            {selectedProcessId && !loadingFields && !fieldsError && (
                <>
                    <FileUploadSection
                        uploadedFile={uploadedFile}
                        fileHeaders={fileHeaders}
                        parsingFile={parsingFile}
                        fileError={fileError}
                        handleFileDrop={(files: FileWithPath[]) => { handleFileDrop(files); setDetailedErrors([]); setImportStatusMessage(null); }}
                        setFileError={setFileError}
                    />
                    {uploadedFile && fileHeaders.length > 0 && (
                        <Group mt="md" grow>
                            <TextInput
                                label="Delimiter for multiple values"
                                description="For fields marked as 'Multiple: Yes'."
                                placeholder="e.g., comma (,) or semicolon (;)"
                                value={globalMultipleDelimiter}
                                onChange={(event) => setGlobalMultipleDelimiter(event.currentTarget.value)}
                            />
                            <TextInput
                                label="Custom Date Format (optional)"
                                description={<>For non-standard dates. Use <a href="https://www.php.net/manual/en/datetime.createfromformat.php" target="_blank" rel="noopener noreferrer">PHP date format</a>.</>}
                                placeholder="e.g., d.m.Y or Y-m-d H:i:s"
                                value={customDateFormat}
                                onChange={(event: React.ChangeEvent<HTMLInputElement>) => setCustomDateFormat(event.currentTarget.value)}
                            />
                        </Group>
                    )}
                </>
            )}
            {loadingFields && (<Stack align="center" mt="md"><Loader /><Text>Loading fields...</Text></Stack>)}
            {!loadingFields && fields && fields.length > 0 && !fieldsError && fileHeaders.length > 0 && (
                <Stack gap="xs" mt="lg">
                    <Title order={3}>3. Map Fields</Title>
                    <Paper shadow="xs" withBorder>
                        <ScrollArea h={400} type="auto">
                            <Table striped highlightOnHover withTableBorder withColumnBorders miw={600} stickyHeader>
                                <Table.Thead>
                                    <Table.Tr><Table.Th>Smart Process Field</Table.Th><Table.Th>Type</Table.Th><Table.Th>Multiple</Table.Th><Table.Th>Map to File Column</Table.Th></Table.Tr>
                                </Table.Thead>
                                <Table.Tbody>{fieldRows}</Table.Tbody>
                            </Table>
                        </ScrollArea>
                    </Paper>
                    <Button
                        mt="md" onClick={handleProceedToImport} loading={isImporting}
                        disabled={Object.values(fieldMappings).every(value => value === null) || !uploadedFile || isImporting || parsingFile}
                    >
                        Proceed to Import
                    </Button>
                </Stack>
            )}
            {!loadingFields && selectedProcessId && (!fields || fields.length === 0) && !fieldsError && !errorProcesses && (
                <Text mt="md">No mappable fields found for the selected smart process.</Text>
            )}
        </Stack>
    );
};

export default SmartProcessSelector;