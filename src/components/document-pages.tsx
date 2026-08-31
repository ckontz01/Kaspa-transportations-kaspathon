"use client";

import Link from "next/link";
import { FormEvent, useCallback, useEffect, useState } from "react";
import { useParams } from "next/navigation";
import { useOsrh } from "@/components/osrh-provider";
import { ProtectedPage } from "@/components/protected-page";
import { apiRequest, errorMessage } from "@/lib/api";
import { shortHash } from "@/lib/ride";

type DriverDocument = {
  id: string;
  accountId: string;
  documentType: "id_card" | "driver_license";
  filename: string;
  contentType: string;
  size: number;
  sha256: string;
  status: string;
  createdAt: string;
  updatedAt: string;
};

export function DriverDocumentsPage() {
  const { state } = useOsrh();
  const [documents, setDocuments] = useState<DriverDocument[]>([]);
  const [loading, setLoading] = useState<string | null>("list");
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const load = useCallback(async () => {
    try {
      const result = await apiRequest<{ documents: DriverDocument[] }>(
        "/api/v1/driver/documents",
      );
      setDocuments(result.documents);
    } catch (caught) {
      setError(errorMessage(caught));
    } finally {
      setLoading(null);
    }
  }, []);
  useEffect(() => {
    if (state.user) void load();
  }, [load, state.user]);
  const upload = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const file = form.get("document") as File;
    if (!file?.size) return;
    if (file.size > 5 * 1024 * 1024) {
      setError("Documents must be 5 MB or smaller.");
      return;
    }
    setLoading(String(form.get("documentType")));
    setError(null);
    setNotice(null);
    try {
      const base64Data = await fileBase64(file);
      await apiRequest<DriverDocument>("/api/v1/driver/documents", {
        method: "POST",
        body: JSON.stringify({
          documentType: form.get("documentType"),
          filename: file.name,
          contentType: file.type,
          base64Data,
        }),
      });
      event.currentTarget.reset();
      setNotice("Document uploaded securely and queued for verification.");
      await load();
    } catch (caught) {
      setError(errorMessage(caught));
    } finally {
      setLoading(null);
    }
  };
  return (
    <ProtectedPage role="driver">
      <div className="page-header">
        <div>
          <h1>Driver Documents</h1>
          <p>
            Upload your ID card and driver licence for operator verification.
          </p>
        </div>
        <span className={`status-badge ${state.user?.verificationStatus}`}>
          {state.user?.verificationStatus}
        </span>
      </div>
      {notice ? <div className="flash flash-success">{notice}</div> : null}
      {error ? <div className="flash flash-error">{error}</div> : null}
      <div className="content-grid">
        <DocumentUpload
          documentType="id_card"
          title="🪪 ID Card"
          current={documents.find((item) => item.documentType === "id_card")}
          loading={loading}
          onSubmit={upload}
        />
        <DocumentUpload
          documentType="driver_license"
          title="🚗 Driver's Licence"
          current={documents.find(
            (item) => item.documentType === "driver_license",
          )}
          loading={loading}
          onSubmit={upload}
        />
      </div>
      <section className="card">
        <h2 className="card-title">Document security</h2>
        <p>
          Accepted formats: JPG, PNG, WebP, or PDF up to 5 MB. Files are
          validated by signature, stored privately in MongoDB Atlas, and served
          only to you or an authenticated OSRH operator.
        </p>
      </section>
    </ProtectedPage>
  );
}

export function OperatorDriverDocumentPage() {
  const { state } = useOsrh();
  const params = useParams<{ driverId: string }>();
  const [documents, setDocuments] = useState<DriverDocument[]>([]);
  const [loading, setLoading] = useState<string | null>("list");
  const [error, setError] = useState<string | null>(null);
  const load = useCallback(async () => {
    try {
      const result = await apiRequest<{ documents: DriverDocument[] }>(
        `/api/v1/operator/drivers/${params.driverId}/documents`,
      );
      setDocuments(result.documents);
    } catch (caught) {
      setError(errorMessage(caught));
    } finally {
      setLoading(null);
    }
  }, [params.driverId]);
  useEffect(() => {
    if (state.user) void load();
  }, [load, state.user]);
  const verify = async (id: string, status: "approved" | "rejected") => {
    setLoading(id);
    try {
      await apiRequest(`/api/v1/operator/documents/${id}`, {
        method: "PATCH",
        body: JSON.stringify({ status }),
      });
      await load();
    } catch (caught) {
      setError(errorMessage(caught));
    } finally {
      setLoading(null);
    }
  };
  return (
    <ProtectedPage role={["operator", "admin"]}>
      <div className="page-header">
        <div>
          <Link className="back-link" href="/operator/drivers">
            ← Drivers Hub
          </Link>
          <h1>Driver Documents</h1>
          <p className="mono">Account {params.driverId}</p>
        </div>
      </div>
      {error ? <div className="flash flash-error">{error}</div> : null}
      <section className="card">
        {loading === "list" ? (
          <div className="page-loading">Loading documents…</div>
        ) : (
          <div className="document-review-grid">
            {documents.map((document) => (
              <article className="document-review-card" key={document.id}>
                <div>
                  <h2>
                    {document.documentType === "id_card"
                      ? "ID Card"
                      : "Driver's Licence"}
                  </h2>
                  <p>
                    {document.filename} · {(document.size / 1024).toFixed(1)} KB
                  </p>
                  <p className="hash">SHA-256 {shortHash(document.sha256)}</p>
                  <span className={`status-badge ${document.status}`}>
                    {document.status}
                  </span>
                </div>
                <div className="action-row">
                  <a
                    className="btn btn-outline"
                    href={`/api/v1/driver/documents/${document.id}/content`}
                    target="_blank"
                    rel="noreferrer"
                  >
                    View document
                  </a>
                  <button
                    className="btn btn-primary"
                    type="button"
                    disabled={loading !== null}
                    onClick={() => void verify(document.id, "approved")}
                  >
                    Approve
                  </button>
                  <button
                    className="btn btn-danger"
                    type="button"
                    disabled={loading !== null}
                    onClick={() => void verify(document.id, "rejected")}
                  >
                    Reject
                  </button>
                </div>
              </article>
            ))}
            {documents.length === 0 ? (
              <div className="empty-state">
                This driver has not uploaded documents.
              </div>
            ) : null}
          </div>
        )}
      </section>
    </ProtectedPage>
  );
}

function DocumentUpload({
  documentType,
  title,
  current,
  loading,
  onSubmit,
}: {
  documentType: DriverDocument["documentType"];
  title: string;
  current?: DriverDocument;
  loading: string | null;
  onSubmit: (event: FormEvent<HTMLFormElement>) => Promise<void>;
}) {
  return (
    <form className="card upload-card" onSubmit={onSubmit}>
      <input type="hidden" name="documentType" value={documentType} />
      <div className="card-header">
        <h2 className="card-title">{title}</h2>
        {current ? (
          <span className={`status-badge ${current.status}`}>
            {current.status}
          </span>
        ) : (
          <span className="status-badge warning">Not uploaded</span>
        )}
      </div>
      {current ? (
        <div className="document-current">
          <p>{current.filename}</p>
          <p className="form-help">
            Uploaded {new Date(current.updatedAt).toLocaleString()}
          </p>
          <a
            href={`/api/v1/driver/documents/${current.id}/content`}
            target="_blank"
            rel="noreferrer"
          >
            View current document
          </a>
        </div>
      ) : null}
      <label className="file-upload-wrapper">
        <input
          type="file"
          name="document"
          accept="image/jpeg,image/png,image/webp,application/pdf"
          required
        />
        <span className="file-upload-icon">📷</span>
        <span className="file-upload-text">
          <strong>Click to upload</strong> or choose a replacement
          <br />
          <small>JPG, PNG, WebP or PDF (max 5 MB)</small>
        </span>
      </label>
      <button
        className="btn btn-primary btn-block"
        type="submit"
        disabled={loading !== null}
      >
        {loading === documentType
          ? "Uploading…"
          : current
            ? "Replace document"
            : "Upload document"}
      </button>
    </form>
  );
}

function fileBase64(file: File) {
  return new Promise<string>((resolve, reject) => {
    const reader = new FileReader();
    reader.onerror = () =>
      reject(new Error("The selected document could not be read."));
    reader.onload = () => {
      const value = String(reader.result || "");
      resolve(value.slice(value.indexOf(",") + 1));
    };
    reader.readAsDataURL(file);
  });
}
