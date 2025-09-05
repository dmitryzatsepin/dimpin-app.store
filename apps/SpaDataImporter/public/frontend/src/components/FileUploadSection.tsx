import { Stack, Title, Text, Group, rem, Alert } from '@mantine/core'; // TextInput удален
import { Dropzone, MIME_TYPES, type FileWithPath } from '@mantine/dropzone';
import { IconAlertCircle, IconUpload, IconX, IconFileText } from '@tabler/icons-react';

interface FileUploadSectionProps {
    uploadedFile: FileWithPath | null;
    parsingFile: boolean;
    fileError: string | null;
    handleFileDrop: (files: FileWithPath[]) => void;
    setFileError: (error: string | null) => void;
    fileHeaders: string[];
}

export const FileUploadSection = ({
    uploadedFile,
    parsingFile,
    fileError,
    handleFileDrop,
    setFileError,
    fileHeaders: _fileHeaders,
}: FileUploadSectionProps) => {
    return (
        <Stack gap="xs" mt="lg">
            <Title order={3}>2. Upload Data File (XLSX or CSV)</Title>
            <Dropzone
                onDrop={handleFileDrop}
                onReject={() => setFileError('File type not accepted or issue with file.')}
                maxSize={5 * 1024 ** 2}
                accept={[
                    MIME_TYPES.csv, MIME_TYPES.xls, MIME_TYPES.xlsx,
                    'application/vnd.ms-excel',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    '.csv', '.xls', '.xlsx'
                ]}
                loading={parsingFile}
            >
                <Group justify="center" gap="xl" mih={120} style={{ pointerEvents: 'none' }}>
                    <Dropzone.Accept>
                        <IconUpload style={{ width: rem(52), height: rem(52), color: 'var(--mantine-color-blue-6)' }} stroke={1.5} />
                    </Dropzone.Accept>
                    <Dropzone.Reject>
                        <IconX style={{ width: rem(52), height: rem(52), color: 'var(--mantine-color-red-6)' }} stroke={1.5} />
                    </Dropzone.Reject>
                    <Dropzone.Idle>
                        <IconFileText style={{ width: rem(52), height: rem(52), color: 'var(--mantine-color-dimmed)' }} stroke={1.5} />
                    </Dropzone.Idle>
                    <div>
                        <Text size="xl" inline>Drag file here or click to select</Text>
                        <Text size="sm" c="dimmed" inline mt={7}>Attach one XLSX or CSV file (max 5MB)</Text>
                    </div>
                </Group>
            </Dropzone>

            {uploadedFile && (
                <Text mt="sm">Uploaded file: <strong>{uploadedFile.name}</strong> ({Math.round(uploadedFile.size / 1024)} KB)</Text>
            )}

            {fileError && (
                <Alert
                    color="red"
                    title="File Error"
                    icon={<IconAlertCircle />}
                    mt="sm"
                    withCloseButton
                    onClose={() => setFileError(null)}
                >
                    {fileError}
                </Alert>
            )}
        </Stack>
    );
};