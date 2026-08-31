"use client";

import { FormEvent, useCallback, useEffect, useMemo, useState } from "react";
import { useOsrh } from "@/components/osrh-provider";
import { ProtectedPage } from "@/components/protected-page";
import { apiRequest, errorMessage } from "@/lib/api";
import type { Contact, MessageRecord } from "@/lib/types";

type Conversation = {
  contact: Contact;
  lastMessage: MessageRecord;
  unread: number;
};
type GdprRequest = {
  id: string;
  requestType: string;
  notes?: string | null;
  status: string;
  createdAt: string;
};

export function MessagesPage() {
  const { state } = useOsrh();
  const [contacts, setContacts] = useState<Contact[]>([]);
  const [conversations, setConversations] = useState<Conversation[]>([]);
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [messages, setMessages] = useState<MessageRecord[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const loadIndex = useCallback(async () => {
    try {
      const [contactResult, conversationResult] = await Promise.all([
        apiRequest<{ contacts: Contact[] }>("/api/v1/messages/contacts"),
        apiRequest<{ conversations: Conversation[] }>(
          "/api/v1/messages/conversations",
        ),
      ]);
      setContacts(contactResult.contacts);
      setConversations(conversationResult.conversations);
      const first =
        conversationResult.conversations[0]?.contact.id ||
        contactResult.contacts[0]?.id;
      setSelectedId((current) => current || first || null);
    } catch (caught) {
      setError(errorMessage(caught));
    }
  }, []);

  const loadMessages = useCallback(async (contactId: string, quiet = false) => {
    if (!quiet) setLoading(true);
    try {
      const result = await apiRequest<{ messages: MessageRecord[] }>(
        `/api/v1/messages/${encodeURIComponent(contactId)}`,
      );
      setMessages(result.messages);
      setError(null);
    } catch (caught) {
      if (!quiet) setError(errorMessage(caught));
    } finally {
      if (!quiet) setLoading(false);
    }
  }, []);

  useEffect(() => {
    if (state.user) void loadIndex();
  }, [loadIndex, state.user]);
  useEffect(() => {
    if (!selectedId) {
      setMessages([]);
      return;
    }
    void loadMessages(selectedId);
    const timer = window.setInterval(
      () => void loadMessages(selectedId, true),
      5000,
    );
    return () => window.clearInterval(timer);
  }, [loadMessages, selectedId]);

  const displayContacts = useMemo(() => {
    const map = new Map<string, Contact>();
    conversations.forEach((item) => map.set(item.contact.id, item.contact));
    contacts.forEach((item) => map.set(item.id, item));
    return [...map.values()];
  }, [contacts, conversations]);
  const selected =
    displayContacts.find((item) => item.id === selectedId) || null;

  const send = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!selectedId) return;
    const form = new FormData(event.currentTarget);
    const content = String(form.get("content") || "").trim();
    if (!content) return;
    setLoading(true);
    setError(null);
    try {
      await apiRequest<MessageRecord>("/api/v1/messages", {
        method: "POST",
        body: JSON.stringify({ recipientId: selectedId, content }),
      });
      event.currentTarget.reset();
      await Promise.all([loadMessages(selectedId, true), loadIndex()]);
    } catch (caught) {
      setError(errorMessage(caught));
    } finally {
      setLoading(false);
    }
  };

  return (
    <ProtectedPage>
      <div className="messages-container">
        <div className="messages-header">
          <h1>Messages</h1>
          <p>Conversations with ride participants and OSRH support.</p>
        </div>
        {error ? <div className="flash flash-error">{error}</div> : null}
        <div className="messages-layout">
          <aside className="conversation-list">
            {displayContacts.map((contact) => {
              const conversation = conversations.find(
                (item) => item.contact.id === contact.id,
              );
              return (
                <button
                  type="button"
                  className={`conversation-button${selectedId === contact.id ? " active" : ""}`}
                  key={contact.id}
                  onClick={() => setSelectedId(contact.id)}
                >
                  <strong>{contact.fullName}</strong>
                  <span className="form-help">
                    {contact.role}
                    {conversation?.unread
                      ? ` · ${conversation.unread} new`
                      : ""}
                  </span>
                  {conversation ? (
                    <span className="conversation-preview">
                      {conversation.lastMessage.content}
                    </span>
                  ) : null}
                </button>
              );
            })}
            {displayContacts.length === 0 ? (
              <div className="empty-state">
                Contacts appear after a ride is assigned or when support is
                available.
              </div>
            ) : null}
          </aside>
          <section className="message-thread">
            <div className="message-thread-header">
              <strong>{selected?.fullName || "Select a conversation"}</strong>
              {selected ? (
                <span className="form-help">{selected.role}</span>
              ) : null}
            </div>
            <div className="message-stream">
              {loading && messages.length === 0 ? (
                <div className="page-loading">Loading messages…</div>
              ) : (
                messages.map((message) => (
                  <div
                    className={`message-bubble${message.senderId === state.user?.id ? " mine" : ""}`}
                    key={message.id}
                  >
                    {message.content}
                    <time>{new Date(message.createdAt).toLocaleString()}</time>
                  </div>
                ))
              )}
              {selected && messages.length === 0 && !loading ? (
                <div className="empty-state">Start the conversation.</div>
              ) : null}
            </div>
            <form className="message-composer" onSubmit={send}>
              <input
                className="form-control"
                name="content"
                aria-label="Message"
                placeholder={
                  selected ? `Message ${selected.fullName}` : "Select a contact"
                }
                maxLength={2000}
                disabled={!selected || loading}
              />
              <button
                className="btn btn-primary"
                type="submit"
                disabled={!selected || loading}
              >
                Send
              </button>
            </form>
          </section>
        </div>
      </div>
    </ProtectedPage>
  );
}

export function PrivacyPage() {
  const { state } = useOsrh();
  const [requests, setRequests] = useState<GdprRequest[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const result = await apiRequest<{ requests: GdprRequest[] }>(
        "/api/v1/privacy/requests",
      );
      setRequests(result.requests);
    } catch (caught) {
      setError(errorMessage(caught));
    } finally {
      setLoading(false);
    }
  }, []);
  useEffect(() => {
    if (state.user) void load();
  }, [load, state.user]);

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    setLoading(true);
    setError(null);
    setNotice(null);
    try {
      await apiRequest("/api/v1/privacy/requests", {
        method: "POST",
        body: JSON.stringify({
          requestType: form.get("requestType"),
          notes: form.get("notes") || null,
        }),
      });
      event.currentTarget.reset();
      setNotice("Your GDPR request has been submitted.");
      await load();
    } catch (caught) {
      setError(errorMessage(caught));
    } finally {
      setLoading(false);
    }
  };

  return (
    <ProtectedPage>
      <div className="page-header">
        <div>
          <h1>Privacy & GDPR</h1>
          <p>Control preferences and exercise your data-protection rights.</p>
        </div>
        <span className="badge badge-info">GDPR</span>
      </div>
      {notice ? <div className="flash flash-success">{notice}</div> : null}
      {error ? <div className="flash flash-error">{error}</div> : null}
      <div className="content-grid">
        <section className="card">
          <h2 className="card-title">Your privacy choices</h2>
          <dl className="detail-list">
            <div>
              <dt>Location tracking</dt>
              <dd>
                {state.user?.preferences?.locationTracking
                  ? "Allowed"
                  : "Disabled"}
              </dd>
            </div>
            <div>
              <dt>Notifications</dt>
              <dd>
                {state.user?.preferences?.notifications
                  ? "Allowed"
                  : "Disabled"}
              </dd>
            </div>
            <div>
              <dt>Email updates</dt>
              <dd>
                {state.user?.preferences?.emailUpdates ? "Allowed" : "Disabled"}
              </dd>
            </div>
            <div>
              <dt>Analytics sharing</dt>
              <dd>
                {state.user?.preferences?.dataSharing ? "Allowed" : "Disabled"}
              </dd>
            </div>
          </dl>
          <p className="covenant-note">
            Wallet signatures and public blockchain transactions cannot be
            erased from Kaspa consensus. OSRH account data in MongoDB follows
            the request process below.
          </p>
        </section>
        <form className="card" onSubmit={submit}>
          <h2 className="card-title">Submit a GDPR request</h2>
          <div className="form-group">
            <label className="form-label" htmlFor="requestType">
              Request type
            </label>
            <select
              className="form-control"
              id="requestType"
              name="requestType"
            >
              <option value="access">Access my data</option>
              <option value="rectification">Correct my data</option>
              <option value="erasure">Erase eligible data</option>
              <option value="restriction">Restrict processing</option>
            </select>
          </div>
          <div className="form-group">
            <label className="form-label" htmlFor="notes">
              Details
            </label>
            <textarea
              className="form-control"
              id="notes"
              name="notes"
              maxLength={1000}
            />
          </div>
          <button className="btn btn-primary" type="submit" disabled={loading}>
            {loading ? "Submitting…" : "Submit request"}
          </button>
        </form>
      </div>
      <section className="card">
        <div className="card-header">
          <h2 className="card-title">Previous requests</h2>
        </div>
        <div className="data-table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                <th>Date</th>
                <th>Type</th>
                <th>Notes</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              {requests.map((request) => (
                <tr key={request.id}>
                  <td>{new Date(request.createdAt).toLocaleString()}</td>
                  <td>{request.requestType}</td>
                  <td>{request.notes || "—"}</td>
                  <td>
                    <span className={`status-badge ${request.status}`}>
                      {request.status}
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {requests.length === 0 ? (
            <div className="empty-state">No privacy requests submitted.</div>
          ) : null}
        </div>
      </section>
    </ProtectedPage>
  );
}
